<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

/**
 * Dispatches tool calls to resources
 */
interface DispatcherInterface
{
    /**
     * Dispatch a tool call and return the result
     */
    public function dispatch(ToolCall $toolCall): ToolResult;
}
