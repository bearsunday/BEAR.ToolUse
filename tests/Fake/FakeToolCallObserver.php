<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolCallObserverInterface;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;

/**
 * Fake observer that records every observed tool call for assertions.
 */
final class FakeToolCallObserver implements ToolCallObserverInterface
{
    /** @var list<array{toolCall: ToolCall, result: ToolResult, durationMs: float}> */
    public array $calls = [];

    #[Override]
    public function observe(ToolCall $toolCall, ToolResult $result, float $durationMs): void
    {
        $this->calls[] = [
            'toolCall' => $toolCall,
            'result' => $result,
            'durationMs' => $durationMs,
        ];
    }
}
