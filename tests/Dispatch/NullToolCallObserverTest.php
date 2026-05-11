<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullToolCallObserver::class)]
final class NullToolCallObserverTest extends TestCase
{
    public function testObserveIsNoop(): void
    {
        $observer = new NullToolCallObserver();
        $toolCall = new ToolCall('call_null', 'noop', []);
        $result = ToolResult::success('call_null', '{}');

        $observer->observe($toolCall, $result, 1.5);

        $this->expectNotToPerformAssertions();
    }
}
