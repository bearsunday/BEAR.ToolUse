<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Schema\Tool;
use Override;

use function assert;

/**
 * Agent runtime for managing LLM conversation loop
 *
 * This agent maintains conversation state across multiple run() calls.
 * Call reset() to clear the conversation history and start fresh.
 */
final class Agent implements AgentInterface
{
    /** @var list<Message> */
    public array $messages = [];

    /** @var list<ToolResult> Server-side results held while awaiting client tool execution */
    private array $pendingToolResults = [];
    private readonly ToolList $toolList;

    /** @param list<Tool> $tools */
    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly array $tools,
        private readonly string $systemPrompt,
        private readonly int $maxIterations = 10,
        private readonly ConfirmationHandlerInterface|null $confirmationHandler = null,
    ) {
        $this->toolList = new ToolList($this->tools);
    }

    /**
     * Run the agent with a user message
     *
     * Note: Messages accumulate across calls. Use reset() to clear history.
     */
    #[Override]
    public function run(string $userMessage): AgentResponse
    {
        $this->messages[] = Message::user($userMessage);

        return $this->loop();
    }

    /**
     * Resume the loop with client tool execution results
     *
     * Call after run() returned STOP_CLIENT_TOOL_USE. Server-side results
     * from the interrupted turn are merged in automatically.
     *
     * @param list<ToolResult> $toolResults
     *
     * @throws InvalidResumeException When no client tool calls are awaiting results,
     * a server call of the interrupted turn lacks its held result, or the supplied
     * result IDs do not match the awaited client calls exactly once each.
     */
    public function resume(array $toolResults): AgentResponse
    {
        ResumeValidator::validate($this->messages, $this->toolList, $this->pendingToolResults, $toolResults);

        $this->messages[]         = Message::toolResults([...$this->pendingToolResults, ...$toolResults]);
        $this->pendingToolResults = [];

        return $this->loop();
    }

    private function loop(): AgentResponse
    {
        for ($i = 0; $i < $this->maxIterations; $i++) {
            $response = $this->client->chat(
                system: $this->systemPrompt,
                messages: $this->messages,
                tools: $this->tools,
            );

            switch ($response->stopReason) {
                case 'end_turn':
                    return AgentResponse::completed($response->content);

                case 'tool_use':
                    $this->messages[] = Message::assistant($response->content);
                    $toolResults      = $this->processToolCalls($response);
                    $clientToolCalls  = $this->clientToolCalls($response);
                    if ($clientToolCalls !== []) {
                        $this->pendingToolResults = $toolResults;

                        return AgentResponse::clientToolUse($response->content, $clientToolCalls, $this->messages);
                    }

                    $this->messages[] = Message::toolResults($toolResults);
                    break;

                case 'max_tokens':
                    $this->messages[] = Message::assistant($response->content);

                    return AgentResponse::maxTokensReached($response->content, $this->messages);

                case 'stop_sequence':
                    return AgentResponse::stopSequenceReached($response->content, $this->messages);

                default:
                    // Unknown stop reason - treat as completed
                    return AgentResponse::completed($response->content);
            }
        }

        return AgentResponse::maxIterationsReached($this->messages);
    }

    /**
     * Clear conversation history to start a new conversation
     */
    #[Override]
    public function reset(): void
    {
        $this->messages           = [];
        $this->pendingToolResults = [];
    }

    /** @return list<ToolResult> */
    private function processToolCalls(LlmResponse $response): array
    {
        $toolResults = [];
        foreach ($response->toolCalls as $toolCall) {
            if ($this->toolList->isClient($toolCall->name)) {
                continue;
            }

            if ($this->isCancelled($toolCall, $response->getText())) {
                $toolResults[] = ToolResult::cancelled($toolCall->id);

                continue;
            }

            $toolResults[] = $this->dispatcher->dispatch($toolCall);
        }

        return $toolResults;
    }

    /** @return list<ToolCall> */
    private function clientToolCalls(LlmResponse $response): array
    {
        $clientToolCalls = [];
        foreach ($response->toolCalls as $toolCall) {
            if (! $this->toolList->isClient($toolCall->name)) {
                continue;
            }

            $clientToolCalls[] = $toolCall;
        }

        return $clientToolCalls;
    }

    private function isCancelled(ToolCall $toolCall, string $llmText): bool
    {
        if (! $this->requiresConfirmation($toolCall)) {
            return false;
        }

        $confirmationHandler = $this->confirmationHandler;
        assert($confirmationHandler instanceof ConfirmationHandlerInterface);

        return ! $confirmationHandler->confirm($toolCall, $llmText);
    }

    private function requiresConfirmation(ToolCall $toolCall): bool
    {
        return $this->confirmationHandler !== null
            && $this->toolList->isConfirmable($toolCall->name);
    }
}
