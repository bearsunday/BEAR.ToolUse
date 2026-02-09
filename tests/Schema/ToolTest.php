<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tool::class)]
final class ToolTest extends TestCase
{
    public function testConstruction(): void
    {
        $tool = new Tool(
            name: 'article_get',
            description: 'Get an article by ID',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Article ID'],
                ],
                'required' => ['id'],
            ],
        );

        $this->assertSame('article_get', $tool->name);
        $this->assertSame('Get an article by ID', $tool->description);
        $this->assertSame('object', $tool->inputSchema['type']);
    }

    public function testJsonSerialize(): void
    {
        $tool = new Tool(
            name: 'article_get',
            description: 'Get an article by ID',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ],
        );

        $json = $tool->jsonSerialize();

        $this->assertSame('article_get', $json['name']);
        $this->assertSame('Get an article by ID', $json['description']);
        $this->assertSame('object', $json['input_schema']['type']);
        $this->assertArrayNotHasKey('confirm', $json);
    }

    public function testJsonSerializeWithConfirm(): void
    {
        $tool = new Tool(
            name: 'article_delete',
            description: 'Delete an article by ID',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ],
            confirm: true,
        );

        $json = $tool->jsonSerialize();

        $this->assertTrue($json['confirm']);
    }
}
