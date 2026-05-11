<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use Override;

/**
 * Default no-op observer
 */
final readonly class NullToolCallObserver implements ToolCallObserverInterface
{
    #[Override]
    public function observe(ToolCall $toolCall, ToolResult $result, float $durationMs): void
    {
        // intentional no-op
    }
}
