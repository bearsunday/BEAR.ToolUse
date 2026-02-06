<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use BEAR\ToolUse\Dispatch\ToolCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmResponse::class)]
final class LlmResponseTest extends TestCase
{
    public function testGetTextWithSingleTextBlock(): void
    {
        $response = new LlmResponse(
            'end_turn',
            [['type' => 'text', 'text' => 'Hello, world!']],
            [],
        );

        $this->assertSame('Hello, world!', $response->getText());
    }

    public function testGetTextWithMultipleTextBlocks(): void
    {
        $response = new LlmResponse(
            'end_turn',
            [
                ['type' => 'text', 'text' => 'First line'],
                ['type' => 'text', 'text' => 'Second line'],
            ],
            [],
        );

        $this->assertSame("First line\nSecond line", $response->getText());
    }

    public function testGetTextWithMixedBlocks(): void
    {
        $response = new LlmResponse(
            'tool_use',
            [
                ['type' => 'text', 'text' => 'I will call a tool'],
                ['type' => 'tool_use', 'id' => 'call_123', 'name' => 'test', 'input' => []],
            ],
            [new ToolCall('call_123', 'test', [])],
        );

        $this->assertSame('I will call a tool', $response->getText());
    }

    public function testGetTextWithNoTextBlocks(): void
    {
        $response = new LlmResponse(
            'tool_use',
            [
                ['type' => 'tool_use', 'id' => 'call_123', 'name' => 'test', 'input' => []],
            ],
            [new ToolCall('call_123', 'test', [])],
        );

        $this->assertSame('', $response->getText());
    }

    public function testGetTextWithTextBlockMissingText(): void
    {
        $response = new LlmResponse(
            'end_turn',
            [
                ['type' => 'text'],
                ['type' => 'text', 'text' => 'Valid text'],
            ],
            [],
        );

        $this->assertSame('Valid text', $response->getText());
    }

    public function testProperties(): void
    {
        $toolCalls = [new ToolCall('call_123', 'test_tool', ['key' => 'value'])];
        $content = [['type' => 'text', 'text' => 'Hello']];

        $response = new LlmResponse('end_turn', $content, $toolCalls);

        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame($content, $response->content);
        $this->assertSame($toolCalls, $response->toolCalls);
    }
}
