<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Types;

/**
 * State accumulated during a single streaming iteration
 *
 * @psalm-import-type ContentBlock from Types
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
