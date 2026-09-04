<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use BEAR\ToolUse\Dispatch\ToolCall;

use function implode;

/**
 * Response from LLM API
 *
 * When `stopReason` is `tool_use`, `content` must carry the `tool_use` blocks of
 * `toolCalls` (same id, name and input). The agent records `content` as the
 * assistant message and pairs the tool results with those blocks, so an adapter
 * that fills `toolCalls` alone produces a conversation the LLM API rejects.
 */
final readonly class LlmResponse
{
    /**
     * @param list<array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}> $content   Response content blocks
     * @param list<ToolCall>                                                                                     $toolCalls Tool calls from LLM
     */
    public function __construct(
        public string $stopReason,
        public array $content,
        public array $toolCalls,
    ) {
    }

    public function getText(): string
    {
        $texts = [];
        foreach ($this->content as $block) {
            if ($block['type'] !== 'text' || ! isset($block['text'])) {
                continue;
            }

            $texts[] = $block['text'];
        }

        return implode("\n", $texts);
    }
}
