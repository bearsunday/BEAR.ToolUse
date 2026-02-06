<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;

/**
 * Fake LLM client for testing
 */
final class FakeLlmClient implements LlmClientInterface
{
    /** @var list<LlmResponse> */
    private array $responses = [];

    private int $callIndex = 0;

    /** @var list<array{system: string, messages: list<Message>, tools: list<Tool>}> */
    public array $calls = [];

    /**
     * Queue a response to be returned
     */
    public function queueResponse(LlmResponse $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * Queue a text response
     */
    public function queueTextResponse(string $text): void
    {
        $this->responses[] = new LlmResponse(
            stopReason: 'end_turn',
            content: [['type' => 'text', 'text' => $text]],
            toolCalls: [],
        );
    }

    /**
     * Queue a tool use response
     *
     * @param array<string, mixed> $input
     */
    public function queueToolUseResponse(string $toolId, string $toolName, array $input): void
    {
        $this->responses[] = new LlmResponse(
            stopReason: 'tool_use',
            content: [
                [
                    'type' => 'tool_use',
                    'id' => $toolId,
                    'name' => $toolName,
                    'input' => $input,
                ],
            ],
            toolCalls: [new ToolCall($toolId, $toolName, $input)],
        );
    }

    /**
     * Queue a max_tokens response
     */
    public function queueMaxTokensResponse(string $partialText): void
    {
        $this->responses[] = new LlmResponse(
            stopReason: 'max_tokens',
            content: [['type' => 'text', 'text' => $partialText]],
            toolCalls: [],
        );
    }

    /**
     * Queue a stop_sequence response
     */
    public function queueStopSequenceResponse(string $text): void
    {
        $this->responses[] = new LlmResponse(
            stopReason: 'stop_sequence',
            content: [['type' => 'text', 'text' => $text]],
            toolCalls: [],
        );
    }

    public function chat(
        string $system,
        array $messages,
        array $tools,
    ): LlmResponse {
        $this->calls[] = [
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools,
        ];

        if (isset($this->responses[$this->callIndex])) {
            return $this->responses[$this->callIndex++];
        }

        return new LlmResponse(
            stopReason: 'end_turn',
            content: [['type' => 'text', 'text' => 'No more responses queued']],
            toolCalls: [],
        );
    }

    public function reset(): void
    {
        $this->responses = [];
        $this->calls = [];
        $this->callIndex = 0;
    }
}
