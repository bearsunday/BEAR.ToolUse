<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\FactoryInterface;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeConfirmationHandler;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Fake\FakeStreamingLlmClient;
use BEAR\ToolUse\Schema\SchemaConverter;
use BEAR\ToolUse\Schema\Tool;
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
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

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

    public function testAddClientTools(): void
    {
        $unflagged = new Tool('ui_update', 'Update a form field on the client', [
            'type' => 'object',
            'properties' => ['field' => ['type' => 'string']],
            'required' => ['field'],
        ]);
        $flagged = new Tool('ui_highlight', 'Highlight a form field on the client', [
            'type' => 'object',
            'properties' => ['field' => ['type' => 'string']],
            'required' => ['field'],
        ], client: true);

        $result = $this->factory->addClientTools([$unflagged, $flagged]);

        $this->assertSame($this->factory, $result);
        $tools = $this->factory->getTools();
        $this->assertCount(2, $tools);
        // The client flag is enforced even when the given Tool lacks it
        $this->assertTrue($tools[0]->client);
        $this->assertSame('ui_update', $tools[0]->name);
        $this->assertTrue($tools[1]->client);
        $this->assertSame($flagged, $tools[1]);
    }

    public function testAddClientToolsRejectsConfirmableTool(): void
    {
        $confirmable = new Tool('ui_update', 'Update a form field on the client', [
            'type' => 'object',
            'properties' => ['field' => ['type' => 'string']],
            'required' => ['field'],
        ], confirm: true);

        $this->expectException(ConfirmableClientToolException::class);
        $this->expectExceptionMessage('ui_update');

        $this->factory->addClientTools([$confirmable]);
    }

    public function testAddClientToolsRejectsNameRegisteredByResources(): void
    {
        $this->factory->addResources(['app://self/article']);
        $duplicate = new Tool('article_get', 'Client tool shadowing a server tool', [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);

        $this->expectException(DuplicateToolNameException::class);
        $this->expectExceptionMessage('article_get');

        $this->factory->addClientTools([$duplicate]);
    }

    public function testAddClientToolsRejectsDuplicateWithinBatch(): void
    {
        $tool = static fn () => new Tool('ui_update', 'Update a form field on the client', [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);

        $this->expectException(DuplicateToolNameException::class);

        $this->factory->addClientTools([$tool(), $tool()]);
    }

    public function testAddClientToolsRejectsDuplicateAcrossCalls(): void
    {
        $tool = static fn () => new Tool('ui_update', 'Update a form field on the client', [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);
        $this->factory->addClientTools([$tool()]);

        $this->expectException(DuplicateToolNameException::class);

        $this->factory->addClientTools([$tool()]);
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
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

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
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

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

    public function testCreateStreamingAgentWithConfirmationHandler(): void
    {
        $confirmationHandler = new FakeConfirmationHandler();
        $streamingClient     = new FakeStreamingLlmClient();
        $injector            = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource            = $injector->getInstance(ResourceInterface::class);
        $resourceFactory     = $injector->getInstance(FactoryInterface::class);

        $registry   = new ToolRegistry();
        $converter  = new SchemaConverter(DocBlockFactory::createInstance());
        $collector  = new ToolCollector($converter, $registry, $resourceFactory);
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

        $factory = new AgentFactory(
            $this->llmClient,
            $dispatcher,
            $collector,
            $registry,
            $confirmationHandler,
            $streamingClient,
        );
        $factory->addResources(['app://self/article']);

        $agent = $factory->createStreaming('You are a helpful assistant.', 5);

        $this->assertInstanceOf(StreamingAgent::class, $agent);
    }
}
