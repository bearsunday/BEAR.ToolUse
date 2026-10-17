<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function objectInputs(): iterable
    {
        yield 'no input streamed' => ['', []];
        yield 'empty object' => ['{}', []];
        yield 'object' => ['{"id": 1, "tags": ["a"]}', ['id' => 1, 'tags' => ['a']]];
        yield 'leading whitespace' => [" \n{\"id\": 1}", ['id' => 1]];

        // PHP turns these keys into integers; the input is still a JSON object
        yield 'numeric keys' => ['{"0": "a", "1": "b"}', ['a', 'b']];
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('objectInputs')]
    public function testInputDecodesJsonObject(string $inputJson, array $expected): void
    {
        $pending = new PendingToolCall('call_1', 'article_get', $inputJson);

        $this->assertSame($expected, $pending->input());
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedInputs(): iterable
    {
        yield 'truncated' => ['{"id": 123, ', 'Syntax error'];
        yield 'whitespace only' => [' ', 'Syntax error'];
        yield 'empty array' => ['[]', 'Tool arguments must be a JSON object'];
        yield 'list' => ['[1, 2]', 'Tool arguments must be a JSON object'];
        yield 'string' => ['"text"', 'Tool arguments must be a JSON object'];
        yield 'null' => ['null', 'Tool arguments must be a JSON object'];
    }

    #[DataProvider('rejectedInputs')]
    public function testInputRejectsMalformedOrNonObjectJson(string $inputJson, string $message): void
    {
        $pending = new PendingToolCall('call_1', 'article_get', $inputJson);

        $this->expectException(JsonException::class);
        $this->expectExceptionMessage($message);

        $pending->input();
    }
}
