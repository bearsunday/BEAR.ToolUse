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
final class Agent implements OptionAwareAgentInterface
{
    /** @var list<Message> */
    public array $messages = [];

    /** @var list<ToolResult> Server-side results held while awaiting client tool execution */
    private array $pendingToolResults = [];

    /** Every registered tool, used to classify client calls on resume */
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
    public function run(string $userMessage, AgentOptions|null $options = null): AgentResponse
    {
        $runTools = $this->resolveTools($options);

        $this->messages[] = Message::user($userMessage);

        return $this->loop($runTools, $options);
    }

    /**
     * Resume the loop with client tool execution results
     *
     * Call after run() returned STOP_CLIENT_TOOL_USE. Server-side results
     * from the interrupted turn are merged in automatically.
     *
     * Not part of AgentInterface / OptionAwareAgentInterface (kept out for BC),
     * so consumers that resume must type against this class rather than against
     * the interface AgentFactory::create() returns.
     *
     * @param list<ToolResult> $toolResults
     *
     * @throws InvalidResumeException When no client tool calls are awaiting results,
     * a server call of the interrupted turn lacks its held result, or the supplied
     * result IDs do not match the awaited client calls exactly once each.
     */
    public function resume(array $toolResults, AgentOptions|null $options = null): AgentResponse
    {
        ResumeValidator::validate($this->messages, $this->toolList, $this->pendingToolResults, $toolResults);

        $runTools = $this->resolveTools($options);

        $this->messages[]         = Message::toolResults([...$this->pendingToolResults, ...$toolResults]);
        $this->pendingToolResults = [];

        return $this->loop($runTools, $options);
    }

    /** @param list<Tool> $runTools */
    private function loop(array $runTools, AgentOptions|null $options): AgentResponse
    {
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
                    if ($response->toolCalls === []) {
                        // Nothing to dispatch: a tool results message would have no
                        // tool_use block to pair with. StreamingAgent ends the same way.
                        $this->recordAssistantResponse($response);

                        return AgentResponse::completed($response->content, $this->messages);
                    }

                    // Recorded unconditionally: the tool_result message that follows
                    // is only valid next to its tool_use blocks
                    $this->messages[] = Message::assistant($response->content);
                    $toolResults      = $this->processToolCalls($response, $requestToolList);
                    $clientToolCalls  = $this->clientToolCalls($response, $requestToolList);
                    if ($clientToolCalls !== []) {
                        $this->pendingToolResults = $toolResults;

                        return AgentResponse::clientToolUse($response->content, $clientToolCalls, $this->messages);
                    }

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
        $this->messages           = [];
        $this->pendingToolResults = [];
    }

    /** @return list<ToolResult> */
    private function processToolCalls(LlmResponse $response, ToolList $toolList): array
    {
        $toolResults = [];
        foreach ($response->toolCalls as $toolCall) {
            if (! $toolList->has($toolCall->name)) {
                $toolResults[] = ToolResult::error($toolCall->id, 'Tool is not enabled: ' . $toolCall->name);

                continue;
            }

            if ($this->toolList->isClient($toolCall->name)) {
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

    /**
     * Client tool calls to hand to the consumer
     *
     * Client-ness comes from the registered tools, not from the request: the
     * conversation is classified the same way on resume, including a stateless
     * resume that never saw this request. A registered client tool that this run
     * disabled is not handed over — `processToolCalls()` already answered it with
     * a "not enabled" error result.
     *
     * @return list<ToolCall>
     */
    private function clientToolCalls(LlmResponse $response, ToolList $requestToolList): array
    {
        $clientToolCalls = [];
        foreach ($response->toolCalls as $toolCall) {
            if (! $this->toolList->isClient($toolCall->name) || ! $requestToolList->has($toolCall->name)) {
                continue;
            }

            $clientToolCalls[] = $toolCall;
        }

        return $clientToolCalls;
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
