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
     * Called once per Dispatcher::dispatch(), after the (post-filter) ToolResult
     * is determined — across success, status>=400, exception, and unknown-tool paths.
     *
     * The observer runs synchronously in the dispatch path. Any exception thrown
     * propagates to the caller of dispatch(). Implementations performing I/O
     * (audit logs, metrics, traces) are responsible for their own error handling.
     *
     * $durationMs measures only Dispatcher::dispatch() (registry lookup + resource
     * invocation + filter + JSON encode); it does not include the observer call
     * itself or any work done by the surrounding Agent loop.
     */
    public function observe(ToolCall $toolCall, ToolResult $result, float $durationMs): void;
}
