<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolList::class)]
final class ToolListTest extends TestCase
{
    public function testIsConfirmableReturnsTrueForConfirmableTool(): void
    {
        $tools = [
            new Tool('delete_user', 'Delete a user', [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ], confirm: true),
        ];

        $toolList = new ToolList($tools);

        $this->assertTrue($toolList->isConfirmable('delete_user'));
    }

    public function testIsConfirmableReturnsFalseForNonConfirmableTool(): void
    {
        $tools = [
            new Tool('get_user', 'Get a user', [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ]),
        ];

        $toolList = new ToolList($tools);

        $this->assertFalse($toolList->isConfirmable('get_user'));
    }

    public function testIsConfirmableReturnsFalseForUnknownTool(): void
    {
        $toolList = new ToolList([]);

        $this->assertFalse($toolList->isConfirmable('unknown'));
    }

    public function testMixedConfirmableTools(): void
    {
        $tools = [
            new Tool('get_user', 'Get a user', [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ]),
            new Tool('delete_user', 'Delete a user', [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ], confirm: true),
        ];

        $toolList = new ToolList($tools);

        $this->assertFalse($toolList->isConfirmable('get_user'));
        $this->assertTrue($toolList->isConfirmable('delete_user'));
    }
}
