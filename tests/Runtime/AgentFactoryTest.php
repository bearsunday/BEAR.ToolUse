<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\FactoryInterface;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeConfirmationHandler;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Fake\FakeStreamingLlmClient;
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
        $resourceFactory = $injector->getInstance(FactoryInterface::class);

        $registry = new ToolRegistry();
        $converter = new SchemaConverter(DocBlockFactory::createInstance());
        $collector = new ToolCollector($converter, $registry, $resourceFactory);
        $dispatcher = new Dispatcher($resource, $registry);

        $this->factory = new AgentFactory($this->llmClient, $dispatcher, $collector, $registry);
    }

    public function testAddResources(): void
    {
        $this->factory->addResources(['app://self/article']);

        $tools = $this->factory->getTools();
        $this->assertCount(1, $tools);
        $this->assertSame('article_get', $tools[0]->name);
    }

    public function testAddMultipleResources(): void
    {
        $this->factory->addResources([
            'app://self/article',
            'app://self/user',
        ]);

        $tools = $this->factory->getTools();
        $this->assertCount(3, $tools);
    }

    public function testFluentInterface(): void
    {
        $result = $this->factory
            ->addResources(['app://self/article'])
            ->addResources(['app://self/user']);

        $this->assertSame($this->factory, $result);
        $this->assertCount(3, $this->factory->getTools());
    }

    public function testCreateAgent(): void
    {
        $this->factory->addResources(['app://self/article']);

        $agent = $this->factory->create('You are a helpful assistant.', 5);

        $this->assertInstanceOf(Agent::class, $agent);
    }

    public function testRegistryIsPopulated(): void
    {
        $this->factory->addResources(['app://self/article']);

        $registry = $this->factory->getRegistry();
        $this->assertTrue($registry->has('article_get'));
    }

    public function testMixedSchemes(): void
    {
        $this->factory->addResources([
            'app://self/article',
            'page://self/article',
        ]);

        $tools = $this->factory->getTools();
        $this->assertCount(2, $tools);
    }

    public function testCreateStreamingAgent(): void
    {
        $streamingClient = new FakeStreamingLlmClient();
        $injector        = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource        = $injector->getInstance(ResourceInterface::class);
        $resourceFactory = $injector->getInstance(FactoryInterface::class);

        $registry   = new ToolRegistry();
        $converter  = new SchemaConverter(DocBlockFactory::createInstance());
        $collector  = new ToolCollector($converter, $registry, $resourceFactory);
        $dispatcher = new Dispatcher($resource, $registry);

        $factory = new AgentFactory($this->llmClient, $dispatcher, $collector, $registry, null, $streamingClient);
        $factory->addResources(['app://self/article']);

        $agent = $factory->createStreaming('You are a helpful assistant.', 5);

        $this->assertInstanceOf(StreamingAgent::class, $agent);
    }

    public function testCreateStreamingAgentThrowsWhenNotConfigured(): void
    {
        $this->expectException(StreamingNotConfiguredException::class);
        $this->expectExceptionMessage('StreamingLlmClientInterface is not configured');

        $this->factory->createStreaming('You are a helpful assistant.');
    }

    public function testCreateAgentWithConfirmationHandler(): void
    {
        $confirmationHandler = new FakeConfirmationHandler();
        $injector            = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource            = $injector->getInstance(ResourceInterface::class);
        $resourceFactory     = $injector->getInstance(FactoryInterface::class);

        $registry  = new ToolRegistry();
        $converter = new SchemaConverter(DocBlockFactory::createInstance());
        $collector = new ToolCollector($converter, $registry, $resourceFactory);
        $dispatcher = new Dispatcher($resource, $registry);

        $factory = new AgentFactory($this->llmClient, $dispatcher, $collector, $registry, $confirmationHandler);
        $factory->addResources(['app://self/article']);

        $this->llmClient->queueToolUseWithTextResponse('call_1', 'article_get', ['id' => 1], 'Getting article 1.');
        $this->llmClient->queueTextResponse('Done.');

        $agent    = $factory->create('You are a helpful assistant.');
        $response = $agent->run('Get article 1');

        $this->assertTrue($response->completed);
        // article_get has confirm: false, so handler should NOT be called
        $this->assertEmpty($confirmationHandler->calls);
    }
}
