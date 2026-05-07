<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

/**
 * Observes tool call invocations
 */
interface ToolCallObserverInterface
{
    /**
     * Observe a single tool call invocation.
     *
     * Called once per dispatch, after the result is determined,
     * regardless of success/error/cancellation/unknown-tool paths.
     */
    public function observe(ToolCall $toolCall, ToolResult $result, float $durationMs): void;
}
