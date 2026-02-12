<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\ToolUse\Fake\FakeSummaryFilter;
use BEAR\ToolUse\Fake\Resource\App\FakeArticleResource;
use BEAR\ToolUse\Fake\Resource\App\FakeFilteredResource;
use BEAR\ToolUse\Fake\Resource\App\FakeFilterInheritResource;
use BEAR\ToolUse\Fake\Resource\App\FakeMethodFilterResource;
use BEAR\ToolUse\Fake\Resource\App\Filtered;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_search;
use function json_decode;
use function json_encode;

#[CoversClass(SchemaConverter::class)]
final class SchemaConverterFilterTest extends TestCase
{
    private SchemaConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new SchemaConverter(DocBlockFactory::createInstance());
    }

    public function testClassLevelFilter(): void
    {
        $tools = $this->converter->convert(Filtered::class, '/filtered');

        $this->assertCount(1, $tools);
        $this->assertSame(FakeSummaryFilter::class, $tools[0]->filter);
    }

    public function testMethodLevelFilter(): void
    {
        $tools = $this->converter->convert(FakeMethodFilterResource::class, '/method-filter');

        $toolNames = array_map(static fn (Tool $t) => $t->name, $tools);

        $getIndex = array_search('method_filter_get', $toolNames, true);
        $postIndex = array_search('method_filter_post', $toolNames, true);

        $this->assertNotFalse($getIndex);
        $this->assertNotFalse($postIndex);

        // onGet has #[Tool(filter: ...)] → filter set
        $this->assertSame(FakeSummaryFilter::class, $tools[$getIndex]->filter);
        // onPost has no filter → null
        $this->assertNull($tools[$postIndex]->filter);
    }

    public function testDefaultFilterIsNull(): void
    {
        $tools = $this->converter->convert(FakeArticleResource::class, '/article');

        $this->assertCount(1, $tools);
        $this->assertNull($tools[0]->filter);
    }

    public function testFilterNotSerializedToJson(): void
    {
        $tools = $this->converter->convert(FakeFilteredResource::class, '/filtered');

        $json = json_encode($tools[0]);
        $decoded = json_decode((string) $json, true);

        $this->assertArrayNotHasKey('filter', $decoded);
    }

    public function testMethodAttributeWithoutFilterInheritsClassFilter(): void
    {
        $tools = $this->converter->convert(FakeFilterInheritResource::class, '/filter-inherit');

        $toolNames = array_map(static fn (Tool $t) => $t->name, $tools);

        $customGetIndex = array_search('custom_filtered_get', $toolNames, true);
        $deleteIndex = array_search('filter_inherit_delete', $toolNames, true);

        $this->assertNotFalse($customGetIndex);
        $this->assertNotFalse($deleteIndex);

        // Method #[Tool(name: 'custom_filtered_get')] without filter → inherits class filter
        $this->assertSame(FakeSummaryFilter::class, $tools[$customGetIndex]->filter);
        // No method attribute → inherits class filter
        $this->assertSame(FakeSummaryFilter::class, $tools[$deleteIndex]->filter);
    }
}
