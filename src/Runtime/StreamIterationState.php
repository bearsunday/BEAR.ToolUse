<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

/**
 * State accumulated during a single streaming iteration
 *
 * @psalm-type PendingToolCall = array{id: string, name: string, inputJson: string}
 * @psalm-type ContentBlock = array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}
 */
final readonly class StreamIterationState
{
    /**
     * @param list<PendingToolCall> $pendingToolCalls
     * @param list<ContentBlock>    $contentBlocks
     */
    public function __construct(
        public string $stopReason,
        public string $currentText,
        public array $pendingToolCalls,
        public array $contentBlocks,
        public string $fullText,
    ) {
    }
}
