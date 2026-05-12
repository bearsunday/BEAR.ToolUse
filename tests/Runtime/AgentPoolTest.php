<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\FactoryInterface;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Schema\SchemaConverter;
use BEAR\ToolUse\Schema\ToolCollector;
use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;

#[CoversClass(AgentProfile::class)]
#[CoversClass(AgentPool::class)]
#[CoversClass(AgentDelegator::class)]
final class AgentPoolTest extends TestCase
{
    public function testRegisterAndGetProfile(): void
    {
        [$pool] = $this->createPool();
        $profile = new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        );

        $pool->register($profile);

        $this->assertTrue($pool->has('critic'));
        $this->assertSame($profile, $pool->get('critic'));
    }

    public function testUnknownAgentThrows(): void
    {
        [$pool] = $this->createPool();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown agent: missing');

        $pool->get('missing');
    }

    public function testInvalidProfileNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid agent name: ask-critic');

        new AgentProfile(
            name: 'ask-critic',
            description: 'Invalid',
            systemPrompt: 'Invalid',
        );
    }

    public function testProfileCreatesToolDefinition(): void
    {
        $profile = new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        );

        $tool = $profile->toTool();

        $this->assertSame('ask_critic', $tool->name);
        $this->assertSame('Review design risks', $tool->description);
        $this->assertSame(['message'], $tool->inputSchema['required']);
    }

    public function testDelegatorAskUsesProfileResources(): void
    {
        [$pool, $llmClient] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
            resources: ['app://self/article'],
        ));
        $delegator = new AgentDelegator($pool);

        $llmClient->queueTextResponse('Risk found.');

        $response = $delegator->ask('critic', 'Review this.');

        $this->assertTrue($response->completed);
        $this->assertSame('Risk found.', $response->getText());
        $this->assertSame('You are a critic.', $llmClient->calls[0]['system']);
        $this->assertSame(['article_get'], array_map(
            static fn ($tool): string => $tool->name,
            $llmClient->calls[0]['tools'],
        ));
    }

    public function testDelegatorAskIsolatesSubagentHistoryPerCall(): void
    {
        [$pool, $llmClient] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));
        $delegator = new AgentDelegator($pool);

        $llmClient->queueTextResponse('First.');
        $llmClient->queueTextResponse('Second.');

        $delegator->ask('critic', 'First task.');
        $delegator->ask('critic', 'Second task.');

        $this->assertCount(1, $llmClient->calls[0]['messages']);
        $this->assertCount(1, $llmClient->calls[1]['messages']);
        $this->assertSame('First task.', $llmClient->calls[0]['messages'][0]->content[0]['text']);
        $this->assertSame('Second task.', $llmClient->calls[1]['messages'][0]->content[0]['text']);
    }

    public function testDelegatorDispatchesSubagentToolCall(): void
    {
        [$pool, $llmClient] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));
        $delegator = new AgentDelegator($pool);

        $llmClient->queueTextResponse('Risk found.');

        $result = $delegator->dispatch(new ToolCall(
            id: 'call_1',
            name: 'ask_critic',
            input: ['message' => 'Review this.', 'context' => ['id' => 1]],
        ));

        $this->assertFalse($result->isError);
        $this->assertSame('Risk found.', $result->content);
        $this->assertSame("Review this.\n\nContext:\n{\"id\":1}", $llmClient->calls[0]['messages'][0]->content[0]['text']);
    }

    public function testDelegatorReturnsErrorForInvalidToolInput(): void
    {
        [$pool] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));
        $delegator = new AgentDelegator($pool);

        $result = $delegator->dispatch(new ToolCall('call_1', 'ask_critic', []));

        $this->assertTrue($result->isError);
        $this->assertSame('Agent tool input "message" must be a string.', $result->content);
    }

    public function testMainAgentCanCallSubagentAsTool(): void
    {
        [$pool, $llmClient, $resourceDispatcher] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));
        $mainAgent = new Agent(
            client: $llmClient,
            dispatcher: new AgentDelegator($pool, $resourceDispatcher),
            tools: $pool->getTools(),
            systemPrompt: 'You are a main agent.',
            maxIterations: 5,
        );

        $llmClient->queueToolUseResponse('call_1', 'ask_critic', ['message' => 'Review this.']);
        $llmClient->queueTextResponse('Risk found.');
        $llmClient->queueTextResponse('Delegation complete.');

        $response = $mainAgent->run('Use critic.');

        $this->assertTrue($response->completed);
        $this->assertSame('Delegation complete.', $response->getText());
        $this->assertSame('You are a main agent.', $llmClient->calls[0]['system']);
        $this->assertSame('You are a critic.', $llmClient->calls[1]['system']);
        $this->assertSame('Risk found.', $llmClient->calls[2]['messages'][2]->content[0]['content']);
    }

    public function testAgentFactoryAddsSubagentTools(): void
    {
        [$pool, $llmClient, $resourceDispatcher, $collector, $registry] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));

        $factory = new AgentFactory($llmClient, $resourceDispatcher, $collector, $registry);
        $factory->addSubagents($pool);

        $this->assertSame('ask_critic', $factory->getTools()[0]->name);
    }

    /** @return array{AgentPool, FakeLlmClient, Dispatcher, ToolCollector, ToolRegistry} */
    private function createPool(): array
    {
        $llmClient = new FakeLlmClient();
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $resourceFactory = $injector->getInstance(FactoryInterface::class);
        $registry = new ToolRegistry();
        $converter = new SchemaConverter(DocBlockFactory::createInstance());
        $collector = new ToolCollector($converter, $registry, $resourceFactory);
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());
        $pool = new AgentPool($llmClient, $dispatcher, $collector);

        return [$pool, $llmClient, $dispatcher, $collector, $registry];
    }
}
