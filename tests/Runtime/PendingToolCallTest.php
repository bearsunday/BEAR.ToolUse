<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingToolCall::class)]
final class PendingToolCallTest extends TestCase
{
    public function testExposesConstructorArgumentsAsProperties(): void
    {
        $pending = new PendingToolCall('call_1', 'article_get', '{"id":1}');

        $this->assertSame('call_1', $pending->id);
        $this->assertSame('article_get', $pending->name);
        $this->assertSame('{"id":1}', $pending->inputJson);
    }
}
