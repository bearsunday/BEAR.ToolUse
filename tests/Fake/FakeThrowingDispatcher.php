<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use RuntimeException;

class FakeThrowingDispatcher implements DispatcherInterface
{
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        throw new RuntimeException('Dispatch failed');
    }
}
