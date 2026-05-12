<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\Tool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentOptions::class)]
final class AgentOptionsTest extends TestCase
{
    public function testNullEnabledToolsDoesNotFilter(): void
    {
        $tools = [
            $this->tool('article_get'),
            $this->tool('article_post'),
        ];

        $options = new AgentOptions();

        $this->assertSame($tools, $options->filterTools($tools));
        $this->assertFalse($options->filtersTools());
    }

    public function testWithToolsFiltersInOriginalToolOrder(): void
    {
        $tools = [
            $this->tool('article_get'),
            $this->tool('article_post'),
            $this->tool('article_delete'),
        ];

        $options = AgentOptions::withTools(['article_delete', 'article_get']);

        $filtered = $options->filterTools($tools);

        $this->assertTrue($options->filtersTools());
        $this->assertSame(['article_get', 'article_delete'], [
            $filtered[0]->name,
            $filtered[1]->name,
        ]);
    }

    public function testEmptyEnabledToolsFiltersAllTools(): void
    {
        $options = AgentOptions::withTools([]);

        $this->assertSame([], $options->filterTools([$this->tool('article_get')]));
        $this->assertTrue($options->filtersTools());
    }

    public function testUnknownToolThrows(): void
    {
        $options = AgentOptions::withTools(['missing_tool']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool(s): missing_tool');

        $options->filterTools([$this->tool('article_get')]);
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, 'Test tool', [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);
    }
}
