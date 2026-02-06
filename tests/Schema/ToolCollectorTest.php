<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\Resource\App\FakeArticleResource;
use BEAR\ToolUse\Fake\Resource\App\FakeCustomNameResource;
use BEAR\ToolUse\Fake\Resource\App\FakeCustomPostResource;
use BEAR\ToolUse\Fake\Resource\App\FakeUserResource;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolCollector::class)]
final class ToolCollectorTest extends TestCase
{
    private ToolCollector $collector;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
        $converter = new SchemaConverter(DocBlockFactory::createInstance());
        $this->collector = new ToolCollector($converter, $this->registry);
    }

    public function testCollectRegistersToolsInRegistry(): void
    {
        $tools = $this->collector->collect(FakeArticleResource::class, '/article');

        $this->assertCount(1, $tools);
        $this->assertTrue($this->registry->has('article_get'));
    }

    public function testCollectReturnsToolDefinitions(): void
    {
        $tools = $this->collector->collect(FakeArticleResource::class, '/article');

        $this->assertSame('article_get', $tools[0]->name);
        $this->assertSame('Get an article by ID', $tools[0]->description);
    }

    public function testCollectAllRegistersMultipleResources(): void
    {
        $tools = $this->collector->collectAll([
            FakeArticleResource::class => '/article',
            FakeUserResource::class => '/user',
        ]);

        $this->assertCount(3, $tools); // 1 from article + 2 from user
        $this->assertTrue($this->registry->has('article_get'));
        $this->assertTrue($this->registry->has('user_get'));
        $this->assertTrue($this->registry->has('user_post'));
    }

    public function testRegistryMappingIsCorrect(): void
    {
        $this->collector->collect(FakeArticleResource::class, '/article');

        $mapping = $this->registry->get('article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('article', $mapping['resourceUri']);
        $this->assertSame('get', $mapping['method']);
    }

    public function testCustomToolNameFallsBackToGet(): void
    {
        // FakeCustomNameResource has a custom tool name 'my_custom_tool'
        // which doesn't match the path prefix pattern
        $this->collector->collect(FakeCustomNameResource::class, '/custom');

        $mapping = $this->registry->get('my_custom_tool');
        $this->assertNotNull($mapping);
        // Default method when can't infer
        $this->assertSame('get', $mapping['method']);
    }

    public function testPathWithHyphensConverted(): void
    {
        $this->collector->collect(FakeArticleResource::class, '/my-article');

        $this->assertTrue($this->registry->has('my_article_get'));

        $mapping = $this->registry->get('my_article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('my-article', $mapping['resourceUri']);
    }

    public function testLeadingSlashStrippedFromResourceUri(): void
    {
        $this->collector->collect(FakeArticleResource::class, '/article');

        $mapping = $this->registry->get('article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('article', $mapping['resourceUri']);
    }

    public function testCustomToolNameWithMethodSuffix(): void
    {
        // FakeCustomPostResource has custom tool name 'custom_action_post'
        $this->collector->collect(FakeCustomPostResource::class, '/custom-post');

        $mapping = $this->registry->get('custom_action_post');
        $this->assertNotNull($mapping);
        // Should infer 'post' from the _post suffix
        $this->assertSame('post', $mapping['method']);
    }
}
