<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\FactoryInterface;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Dispatch\ToolResult;
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
#[CoversClass(AgentFactory::class)]
#[CoversClass(DenyConfirmationHandler::class)]
#[CoversClass(ProfiledAgent::class)]
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

    public function testDelegatorCanDispatchKnownSubagentToolOnly(): void
    {
        [$pool] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));
        $delegator = new AgentDelegator($pool);

        $this->assertFalse($delegator->canDispatch('article_get'));
        $this->assertTrue($delegator->canDispatch('ask_critic'));
        $this->assertFalse($delegator->canDispatch('ask_missing'));
    }

    public function testDelegatorFallsBackForNonAgentTool(): void
    {
        [$pool] = $this->createPool();
        $fallback = new class implements DispatcherInterface {
            public function dispatch(ToolCall $toolCall): ToolResult
            {
                return ToolResult::success($toolCall->id, 'fallback result');
            }
        };
        $delegator = new AgentDelegator($pool, $fallback);

        $result = $delegator->dispatch(new ToolCall('call_1', 'article_get', []));

        $this->assertFalse($result->isError);
        $this->assertSame('fallback result', $result->content);
    }

    public function testDelegatorReturnsUnknownToolWithoutFallback(): void
    {
        [$pool] = $this->createPool();
        $delegator = new AgentDelegator($pool);

        $result = $delegator->dispatch(new ToolCall('call_1', 'article_get', []));

        $this->assertTrue($result->isError);
        $this->assertSame('Unknown tool: article_get', $result->content);
    }

    public function testDelegatorReturnsUnknownAgentForAskTool(): void
    {
        [$pool] = $this->createPool();
        $delegator = new AgentDelegator($pool);

        $result = $delegator->dispatch(new ToolCall('call_1', 'ask_missing', ['message' => 'Hi']));

        $this->assertTrue($result->isError);
        $this->assertSame('Unknown agent: missing', $result->content);
    }

    public function testDelegatorFallsBackForUnknownAskTool(): void
    {
        [$pool] = $this->createPool();
        $fallback = new class implements DispatcherInterface {
            public function dispatch(ToolCall $toolCall): ToolResult
            {
                return ToolResult::success($toolCall->id, 'fallback ask result');
            }
        };
        $delegator = new AgentDelegator($pool, $fallback);

        $result = $delegator->dispatch(new ToolCall('call_1', 'ask_custom', ['message' => 'Hi']));

        $this->assertFalse($result->isError);
        $this->assertSame('fallback ask result', $result->content);
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

    public function testDelegatorReturnsErrorForInvalidContextInput(): void
    {
        [$pool] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));
        $delegator = new AgentDelegator($pool);

        $stringContext = $delegator->dispatch(new ToolCall('call_1', 'ask_critic', [
            'message' => 'Review this.',
            'context' => 'invalid',
        ]));
        $listContext = $delegator->dispatch(new ToolCall('call_2', 'ask_critic', [
            'message' => 'Review this.',
            'context' => ['invalid'],
        ]));

        $this->assertTrue($stringContext->isError);
        $this->assertSame('Agent tool input "context" must be an object.', $stringContext->content);
        $this->assertTrue($listContext->isError);
        $this->assertSame('Agent tool input "context" must be an object.', $listContext->content);
    }

    public function testDelegatorReturnsErrorWhenSubagentDoesNotComplete(): void
    {
        [$pool, $llmClient] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));
        $delegator = new AgentDelegator($pool);

        $llmClient->queueMaxTokensResponse('Partial.');

        $result = $delegator->dispatch(new ToolCall('call_1', 'ask_critic', ['message' => 'Review this.']));

        $this->assertTrue($result->isError);
        $this->assertSame('Subagent stopped: max_tokens', $result->content);
    }

    public function testDelegatorConvertsSubagentExceptionToToolError(): void
    {
        [$pool] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
            resources: ['app://self/article'],
            options: AgentOptions::withTools(['missing_tool']),
        ));
        $delegator = new AgentDelegator($pool);

        $result = $delegator->dispatch(new ToolCall('call_1', 'ask_critic', ['message' => 'Review this.']));

        $this->assertTrue($result->isError);
        $this->assertSame('InvalidArgumentException: Unknown tool(s): missing_tool', $result->content);
    }

    public function testPoolCreateAppliesProfileOptions(): void
    {
        [$pool, $llmClient] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
            resources: ['app://self/article', 'app://self/error'],
            options: AgentOptions::withTools(['article_get']),
        ));

        $agent = $pool->create('critic');
        $llmClient->queueToolUseResponse('call_1', 'error_get', []);
        $llmClient->queueTextResponse('Rejected.');

        $response = $agent->run('Try hidden tool.');

        $this->assertTrue($response->completed);
        $toolResultMessage = $llmClient->calls[1]['messages'][2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertSame('Tool is not enabled: error_get', $toolResultMessage->content[0]['content']);
    }

    public function testProfiledAgentResetDelegatesToInnerAgent(): void
    {
        [$pool, $llmClient] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));

        $agent = $pool->create('critic');
        $llmClient->queueTextResponse('Done.');
        $agent->run('Review this.');
        $agent->reset();
        $llmClient->queueTextResponse('Again.');
        $agent->run('Review again.');

        $this->assertCount(1, $llmClient->calls[1]['messages']);
    }

    public function testDenyConfirmationHandlerDeniesEveryCall(): void
    {
        $handler = new DenyConfirmationHandler();

        $this->assertFalse($handler->confirm(new ToolCall('call_1', 'dangerous_tool', []), 'Confirm?'));
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

    public function testAgentFactoryWiresSubagentDispatcher(): void
    {
        [$pool, $llmClient, $resourceDispatcher, $collector, $registry] = $this->createPool();
        $pool->register(new AgentProfile(
            name: 'critic',
            description: 'Review design risks',
            systemPrompt: 'You are a critic.',
        ));

        $factory = new AgentFactory($llmClient, $resourceDispatcher, $collector, $registry);
        $factory->addSubagents($pool);

        $llmClient->queueToolUseResponse('call_1', 'ask_critic', ['message' => 'Review this.']);
        $llmClient->queueTextResponse('Risk found.');
        $llmClient->queueTextResponse('Delegation complete.');

        $agent = $factory->create('You are a main agent.');
        $response = $agent->run('Use critic.');

        $this->assertTrue($response->completed);
        $this->assertSame('Risk found.', $llmClient->calls[2]['messages'][2]->content[0]['content']);
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
