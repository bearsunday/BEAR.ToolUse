<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\Resource\Exception\ResourceNotFoundException;
use BEAR\ToolUse\Fake\Resource\App\StatusError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ToolErrorFormatter::class)]
#[CoversClass(DebugToolErrorFormatter::class)]
final class ToolErrorFormatterTest extends TestCase
{
    public function testClientErrorStatusPassesBodyOn(): void
    {
        $message = (new ToolErrorFormatter())->fromStatus((new StatusError())->onGet(400));

        $this->assertSame('400: {"error":"Validation failed","details":"The \"name\" field is required"}', $message);
    }

    public function testServerErrorStatusHidesBody(): void
    {
        $message = (new ToolErrorFormatter())->fromStatus((new StatusError())->onGet(503));

        $this->assertSame('503: Tool execution failed.', $message);
    }

    public function testClientErrorExceptionPassesMessageOn(): void
    {
        $message = (new ToolErrorFormatter())->fromThrowable(new ResourceNotFoundException('/user?id=999'));

        $this->assertSame('404: /user?id=999', $message);
    }

    public function testOtherExceptionHidesClassAndMessage(): void
    {
        $formatter = new ToolErrorFormatter();

        $this->assertSame('Tool execution failed.', $formatter->fromThrowable(new RuntimeException('dsn=mysql://root:secret@db')));
        $this->assertSame('Tool execution failed.', $formatter->fromThrowable(new RuntimeException('upstream', 503)));
    }

    public function testDebugFormatterDisclosesEverything(): void
    {
        $formatter = new DebugToolErrorFormatter();

        $this->assertStringContainsString('Validation failed', $formatter->fromStatus((new StatusError())->onGet(503)));
        $this->assertSame('RuntimeException: Test error', $formatter->fromThrowable(new RuntimeException('Test error')));
    }
}
