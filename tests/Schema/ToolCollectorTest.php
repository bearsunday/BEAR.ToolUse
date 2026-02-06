<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\FactoryInterface;
use BEAR\Resource\Module\ResourceModule;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

#[CoversClass(ToolCollector::class)]
final class ToolCollectorTest extends TestCase
{
    private ToolCollector $collector;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resourceFactory = $injector->getInstance(FactoryInterface::class);

        $this->registry = new ToolRegistry();
        $converter = new SchemaConverter(DocBlockFactory::createInstance());
        $this->collector = new ToolCollector($converter, $this->registry, $resourceFactory);
    }

    public function testCollectRegistersToolsInRegistry(): void
    {
        $tools = $this->collector->collect(['app://self/article']);

        $this->assertCount(1, $tools);
        $this->assertTrue($this->registry->has('article_get'));
    }

    public function testCollectReturnsToolDefinitions(): void
    {
        $tools = $this->collector->collect(['app://self/article']);

        $this->assertSame('article_get', $tools[0]->name);
        $this->assertSame('Get an article by ID', $tools[0]->description);
    }

    public function testCollectMultipleResources(): void
    {
        $tools = $this->collector->collect([
            'app://self/article',
            'app://self/user',
        ]);

        $this->assertCount(3, $tools); // 1 from article + 2 from user
        $this->assertTrue($this->registry->has('article_get'));
        $this->assertTrue($this->registry->has('user_get'));
        $this->assertTrue($this->registry->has('user_post'));
    }

    public function testRegistryMappingIsCorrect(): void
    {
        $this->collector->collect(['app://self/article']);

        $mapping = $this->registry->get('article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('app://self/article', $mapping['resourceUri']);
        $this->assertSame('get', $mapping['method']);
    }

    public function testCustomToolNameFallsBackToGet(): void
    {
        $this->collector->collect(['app://self/custom']);

        $mapping = $this->registry->get('my_custom_tool');
        $this->assertNotNull($mapping);
        $this->assertSame('get', $mapping['method']);
    }

    public function testPathWithHyphensConverted(): void
    {
        $this->collector->collect(['app://self/my-article']);

        $this->assertTrue($this->registry->has('my_article_get'));

        $mapping = $this->registry->get('my_article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('app://self/my-article', $mapping['resourceUri']);
    }

    public function testFullUriStoredInRegistry(): void
    {
        $this->collector->collect(['app://self/article']);

        $mapping = $this->registry->get('article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('app://self/article', $mapping['resourceUri']);
    }

    public function testCustomToolNameWithMethodSuffix(): void
    {
        $this->collector->collect(['app://self/custom-post']);

        $mapping = $this->registry->get('custom_action_post');
        $this->assertNotNull($mapping);
        $this->assertSame('post', $mapping['method']);
    }

    public function testDifferentSchemes(): void
    {
        $tools = $this->collector->collect(['page://self/article']);

        $this->assertCount(1, $tools);
        $mapping = $this->registry->get('article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('page://self/article', $mapping['resourceUri']);
    }
}
