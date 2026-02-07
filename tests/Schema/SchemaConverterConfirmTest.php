<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\ToolUse\Fake\Resource\App\FakeArticleResource;
use BEAR\ToolUse\Fake\Resource\App\FakeConfirmableResource;
use BEAR\ToolUse\Fake\Resource\App\FakeMethodConfirmResource;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_search;
use function json_decode;
use function json_encode;

#[CoversClass(SchemaConverter::class)]
final class SchemaConverterConfirmTest extends TestCase
{
    private SchemaConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new SchemaConverter(DocBlockFactory::createInstance());
    }

    public function testClassLevelConfirm(): void
    {
        $tools = $this->converter->convert(FakeConfirmableResource::class, '/confirmable');

        $this->assertCount(2, $tools);
        foreach ($tools as $tool) {
            $this->assertTrue($tool->confirm);
        }
    }

    public function testMethodLevelConfirm(): void
    {
        $tools = $this->converter->convert(FakeMethodConfirmResource::class, '/method-confirm');

        $toolNames = array_map(static fn (Tool $t) => $t->name, $tools);

        $getIndex = array_search('method_confirm_get', $toolNames, true);
        $deleteIndex = array_search('method_confirm_delete', $toolNames, true);

        // onGet has no confirm attribute → false
        $this->assertFalse($tools[$getIndex]->confirm);
        // onDelete has #[Tool(confirm: true)] → true
        $this->assertTrue($tools[$deleteIndex]->confirm);
    }

    public function testDefaultConfirmIsFalse(): void
    {
        $tools = $this->converter->convert(FakeArticleResource::class, '/article');

        $this->assertCount(1, $tools);
        $this->assertFalse($tools[0]->confirm);
    }

    public function testConfirmNotSerializedToJson(): void
    {
        $tools = $this->converter->convert(FakeConfirmableResource::class, '/confirmable');

        $json = json_encode($tools[0]);
        $decoded = json_decode((string) $json, true);

        $this->assertArrayHasKey('name', $decoded);
        $this->assertArrayHasKey('description', $decoded);
        $this->assertArrayHasKey('input_schema', $decoded);
        $this->assertArrayNotHasKey('confirm', $decoded);
    }
}
