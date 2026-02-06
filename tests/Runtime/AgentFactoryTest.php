<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Fake\Resource\App\FakeArticleResource;
use BEAR\ToolUse\Fake\Resource\App\FakeUserResource;
use BEAR\ToolUse\Schema\SchemaConverter;
use BEAR\ToolUse\Schema\ToolCollector;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

#[CoversClass(AgentFactory::class)]
final class AgentFactoryTest extends TestCase
{
    private AgentFactory $factory;
    private FakeLlmClient $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = new FakeLlmClient();
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);

        $registry = new ToolRegistry();
        $converter = new SchemaConverter(DocBlockFactory::createInstance());
        $collector = new ToolCollector($converter, $registry);
        $dispatcher = new Dispatcher($resource, $registry);

        $this->factory = new AgentFactory($this->llmClient, $dispatcher, $collector, $registry);
    }

    public function testAddResource(): void
    {
        $this->factory->addResource(FakeArticleResource::class, '/article');

        $tools = $this->factory->getTools();
        $this->assertCount(1, $tools);
        $this->assertSame('article_get', $tools[0]->name);
    }

    public function testAddResources(): void
    {
        $this->factory->addResources([
            FakeArticleResource::class => '/article',
            FakeUserResource::class => '/user',
        ]);

        $tools = $this->factory->getTools();
        $this->assertCount(3, $tools);
    }

    public function testFluentInterface(): void
    {
        $result = $this->factory
            ->addResource(FakeArticleResource::class, '/article')
            ->addResource(FakeUserResource::class, '/user');

        $this->assertSame($this->factory, $result);
        $this->assertCount(3, $this->factory->getTools());
    }

    public function testCreateAgent(): void
    {
        $this->factory->addResource(FakeArticleResource::class, '/article');

        $agent = $this->factory->create('You are a helpful assistant.', 5);

        $this->assertInstanceOf(Agent::class, $agent);
    }

    public function testRegistryIsPopulated(): void
    {
        $this->factory->addResource(FakeArticleResource::class, '/article');

        $registry = $this->factory->getRegistry();
        $this->assertTrue($registry->has('article_get'));
    }
}
