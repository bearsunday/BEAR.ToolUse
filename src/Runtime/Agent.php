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

use function array_key_exists;
use function assert;

/**
 * Agent runtime for managing LLM conversation loop
 *
 * This agent maintains conversation state across multiple run() calls.
 * Call reset() to clear the conversation history and start fresh.
 */
final class Agent implements AgentInterface
{
    private const CANCELLED_MESSAGE = 'User cancelled this operation.';

    /** @var list<Message> */
    public array $messages = [];

    /** @var array<string, bool> */
    private readonly array $confirmableTools;

    /** @param list<Tool> $tools */
    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly array $tools,
        private readonly string $systemPrompt,
        private readonly int $maxIterations = 10,
        private readonly ConfirmationHandlerInterface|null $confirmationHandler = null,
    ) {
        $confirmable = [];
        foreach ($this->tools as $tool) {
            if (! $tool->confirm) {
                continue;
            }

            $confirmable[$tool->name] = true;
        }

        $this->confirmableTools = $confirmable;
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
        $this->messages = [];
    }

    /** @return list<ToolResult> */
    private function processToolCalls(LlmResponse $response): array
    {
        $toolResults = [];
        foreach ($response->toolCalls as $toolCall) {
            if ($this->requiresConfirmation($toolCall)) {
                $llmText             = $response->getText();
                $confirmationHandler = $this->confirmationHandler;
                assert($confirmationHandler instanceof ConfirmationHandlerInterface);
                if (! $confirmationHandler->confirm($toolCall, $llmText)) {
                    $toolResults[] = ToolResult::error($toolCall->id, self::CANCELLED_MESSAGE);

                    continue;
                }
            }

            $toolResults[] = $this->dispatcher->dispatch($toolCall);
        }

        return $toolResults;
    }

    private function requiresConfirmation(ToolCall $toolCall): bool
    {
        return $this->confirmationHandler !== null
            && array_key_exists($toolCall->name, $this->confirmableTools);
    }
}
