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

    /** @param list<Tool> $tools */
    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly array $tools,
        private readonly string $systemPrompt,
        private readonly int $maxIterations = 10,
        private readonly ConfirmationHandlerInterface|null $confirmationHandler = null,
    ) {
    }

    /**
     * Run the agent with a user message
     *
     * Note: Messages accumulate across calls. Use reset() to clear history.
     */
    #[Override]
    public function run(string $userMessage, AgentOptions|null $options = null): AgentResponse
    {
        $runTools = $this->resolveTools($options);
        $enforceToolList = $options?->enforcesToolList() ?? false;

        $this->messages[] = Message::user($userMessage);

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $request = $this->createRequest($runTools, $options);
            $response = $this->client->chat(
                system: $request->systemPrompt,
                messages: $request->messages,
                tools: $request->tools,
            );
            $response = $options?->processResponse($response, $request) ?? $response;
            $requestToolList = new ToolList($request->tools);

            switch ($response->stopReason) {
                case 'end_turn':
                    $this->recordAssistantResponse($response);

                    return AgentResponse::completed($response->content, $this->messages);

                case 'tool_use':
                    $this->messages[] = Message::assistant($response->content);
                    $toolResults      = $this->processToolCalls($response, $requestToolList, $enforceToolList);
                    $this->messages[] = Message::toolResults($toolResults);
                    break;

                case 'max_tokens':
                    $this->recordAssistantResponse($response);

                    return AgentResponse::maxTokensReached($response->content, $this->messages);

                case 'stop_sequence':
                    $this->recordAssistantResponse($response);

                    return AgentResponse::stopSequenceReached($response->content, $this->messages);

                default:
                    // Unknown stop reason - treat as completed
                    $this->recordAssistantResponse($response);

                    return AgentResponse::completed($response->content, $this->messages);
            }
        }

        return AgentResponse::maxIterationsReached($this->messages);
    }

    /** @return list<Tool> */
    private function resolveTools(AgentOptions|null $options): array
    {
        return $options?->filterTools($this->tools) ?? $this->tools;
    }

    /** @param list<Tool> $tools */
    private function createRequest(array $tools, AgentOptions|null $options): LlmRequest
    {
        $request = new LlmRequest($this->systemPrompt, $this->messages, $tools);

        return $options?->processRequest($request) ?? $request;
    }

    /**
     * Clear conversation history to start a new conversation
     */
    #[Override]
    public function reset(): void
    {
        $this->messages = [];
    }

    /** @return list<ToolResult> */
    private function processToolCalls(LlmResponse $response, ToolList $toolList, bool $enforceToolList): array
    {
        $toolResults = [];
        foreach ($response->toolCalls as $toolCall) {
            if ($enforceToolList && ! $toolList->has($toolCall->name)) {
                $toolResults[] = ToolResult::error($toolCall->id, 'Tool is not enabled: ' . $toolCall->name);

                continue;
            }

            if ($this->isCancelled($toolCall, $response->getText(), $toolList)) {
                $toolResults[] = ToolResult::cancelled($toolCall->id);

                continue;
            }

            $toolResults[] = $this->dispatcher->dispatch($toolCall);
        }

        return $toolResults;
    }

    private function isCancelled(ToolCall $toolCall, string $llmText, ToolList $toolList): bool
    {
        if (! $this->requiresConfirmation($toolCall, $toolList)) {
            return false;
        }

        $confirmationHandler = $this->confirmationHandler;
        assert($confirmationHandler instanceof ConfirmationHandlerInterface);

        return ! $confirmationHandler->confirm($toolCall, $llmText);
    }

    private function requiresConfirmation(ToolCall $toolCall, ToolList $toolList): bool
    {
        return $this->confirmationHandler !== null
            && $toolList->isConfirmable($toolCall->name);
    }

    private function recordAssistantResponse(LlmResponse $response): void
    {
        if ($response->content === []) {
            return;
        }

        $this->messages[] = Message::assistant($response->content);
    }
}
