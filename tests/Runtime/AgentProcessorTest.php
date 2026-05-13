<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeInputProcessor;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Fake\FakeOutputProcessor;
use BEAR\ToolUse\Fake\FakeStreamingLlmClient;
use BEAR\ToolUse\Fake\FakeToolFilteringInputProcessor;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Schema\Tool;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use UnexpectedValueException;

use function iterator_to_array;

#[CoversClass(Agent::class)]
#[CoversClass(StreamingAgent::class)]
#[CoversClass(AgentOptions::class)]
#[CoversClass(LlmRequest::class)]
final class AgentProcessorTest extends TestCase
{
    public function testInputProcessorChangesAgentRequest(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgent($llmClient);
        $processor = new FakeInputProcessor(' Use memory.', 'remember user context');

        $llmClient->queueTextResponse('Done.');

        $agent->run('Hello', AgentOptions::withProcessors(inputProcessors: [$processor]));

        $this->assertSame(1, $processor->calls);
        $this->assertSame('You are a helpful assistant. Use memory.', $llmClient->calls[0]['system']);
        $this->assertSame('remember user context', $llmClient->calls[0]['messages'][1]->content[0]['text']);
    }

    public function testOutputProcessorChangesAgentResponse(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgent($llmClient);
        $processor = new FakeOutputProcessor('Processed response.');

        $llmClient->queueTextResponse('Raw response.');

        $response = $agent->run('Hello', AgentOptions::withProcessors(outputProcessors: [$processor]));

        $this->assertSame(1, $processor->calls);
        $this->assertSame('Processed response.', $response->getText());
    }

    public function testAgentOutputProcessorMustReturnLlmResponse(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgent($llmClient);
        $processor = new class implements OutputProcessorInterface {
            #[Override]
            public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent
            {
                return new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'wrong type']);
            }
        };

        $llmClient->queueTextResponse('Raw response.');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Output processor must return LlmResponse for non-streaming calls.');

        $agent->run('Hello', AgentOptions::withProcessors(outputProcessors: [$processor]));
    }

    public function testProcessorsRunOnEveryToolLoopIteration(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgent($llmClient);
        $inputProcessor = new FakeInputProcessor(memoryText: 'memory');
        $outputProcessor = new FakeOutputProcessor('processed');

        $llmClient->queueToolUseResponse('call_1', 'article_get', ['id' => 1]);
        $llmClient->queueTextResponse('Done.');

        $agent->run('Use tool', AgentOptions::withProcessors(
            inputProcessors: [$inputProcessor],
            outputProcessors: [$outputProcessor],
        ));

        $this->assertSame(2, $inputProcessor->calls);
        $this->assertSame(2, $outputProcessor->calls);
        $this->assertSame('memory', $llmClient->calls[1]['messages'][3]->content[0]['text']);
    }

    public function testAgentOutputProcessorMustPreserveToolUseContentBlocks(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgent($llmClient);
        $processor = new class implements OutputProcessorInterface {
            #[Override]
            public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent
            {
                if (! $output instanceof LlmResponse) {
                    return $output;
                }

                return new LlmResponse(
                    $output->stopReason,
                    [['type' => 'text', 'text' => 'corrupted']],
                    $output->toolCalls,
                );
            }
        };

        $llmClient->queueToolUseResponse('call_1', 'article_get', ['id' => 1]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Output processor must preserve tool_use content blocks for tool calls.');

        $agent->run('Use tool', AgentOptions::withProcessors(outputProcessors: [$processor]));
    }

    public function testAgentOutputProcessorMustPreserveToolUseNameAndInput(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgent($llmClient);
        $processor = new class implements OutputProcessorInterface {
            #[Override]
            public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent
            {
                if (! $output instanceof LlmResponse) {
                    return $output;
                }

                $content = [];
                foreach ($output->content as $block) {
                    if (($block['type'] ?? null) === 'tool_use') {
                        $block['name'] = 'other_get';
                    }

                    $content[] = $block;
                }

                return new LlmResponse($output->stopReason, $content, $output->toolCalls);
            }
        };

        $llmClient->queueToolUseResponse('call_1', 'article_get', ['id' => 1]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Output processor must preserve tool_use content blocks for tool calls.');

        $agent->run('Use tool', AgentOptions::withProcessors(outputProcessors: [$processor]));
    }

    public function testInputProcessorFilteredToolIsEnforced(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgentWithArticleAndErrorTools($llmClient);

        $llmClient->queueToolUseResponse('call_1', 'error_get', []);
        $llmClient->queueTextResponse('Recovered.');

        $agent->run('Try error tool', AgentOptions::withProcessors(
            inputProcessors: [new FakeToolFilteringInputProcessor('article_get')],
        ));

        $toolResultMessage = $llmClient->calls[1]['messages'][2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertSame('Tool is not enabled: error_get', $toolResultMessage->content[0]['content']);
    }

    public function testAgentRejectsUnadvertisedRegisteredToolWithoutOptions(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgentWithArticleToolAndErrorRegistry($llmClient);

        $llmClient->queueToolUseResponse('call_1', 'error_get', []);
        $llmClient->queueTextResponse('Recovered.');

        $response = $agent->run('Try hidden error tool');

        $this->assertTrue($response->completed);
        $toolResultMessage = $llmClient->calls[1]['messages'][2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertSame('Tool is not enabled: error_get', $toolResultMessage->content[0]['content']);
    }

    public function testStreamingProcessorsAreApplied(): void
    {
        $llmClient = new FakeStreamingLlmClient();
        $agent = $this->createStreamingAgent($llmClient);
        $inputProcessor = new FakeInputProcessor(' Stream.', 'stream memory');
        $outputProcessor = new FakeOutputProcessor('Processed');

        $llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Raw']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($agent->runStream('Hi', AgentOptions::withProcessors(
            inputProcessors: [$inputProcessor],
            outputProcessors: [$outputProcessor],
        )));

        $this->assertSame(1, $inputProcessor->calls);
        $this->assertSame(3, $outputProcessor->calls);
        $this->assertSame('You are a helpful assistant. Stream.', $llmClient->calls[0]['system']);
        $this->assertSame('stream memory', $llmClient->calls[0]['messages'][1]->content[0]['text']);
        $this->assertSame('Processed', $events[0]->data['text']);
        $this->assertSame('Processed', $events[1]->data['fullText']);
    }

    public function testStreamingAgentRejectsUnadvertisedRegisteredToolWithoutOptions(): void
    {
        $llmClient = new FakeStreamingLlmClient();
        $agent = $this->createStreamingAgentWithArticleToolAndErrorRegistry($llmClient);
        $llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'error_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{}']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Recovered.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($agent->runStream('Try hidden error tool'));

        $toolResultMessage = $llmClient->calls[1]['messages'][2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertSame('Tool is not enabled: error_get', $toolResultMessage->content[0]['content']);
    }

    public function testStreamingOutputProcessorMustReturnStreamEvent(): void
    {
        $llmClient = new FakeStreamingLlmClient();
        $agent = $this->createStreamingAgent($llmClient);
        $processor = new class implements OutputProcessorInterface {
            #[Override]
            public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent
            {
                return new LlmResponse('end_turn', [['type' => 'text', 'text' => 'wrong type']], []);
            }
        };

        $llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Raw']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Output processor must return StreamEvent for streaming calls.');

        iterator_to_array($agent->runStream('Hi', AgentOptions::withProcessors(outputProcessors: [$processor])));
    }

    public function testStreamingOutputProcessorMustPreserveStreamEventType(): void
    {
        $llmClient = new FakeStreamingLlmClient();
        $agent = $this->createStreamingAgent($llmClient);
        $processor = new class implements OutputProcessorInterface {
            #[Override]
            public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent
            {
                if ($output instanceof StreamEvent && $output->type === StreamEvent::TOOL_USE_START) {
                    return new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'corrupted']);
                }

                return $output;
            }
        };

        $llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"id":1}']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Output processor must preserve stream event type.');

        iterator_to_array($agent->runStream('Use tool', AgentOptions::withProcessors(outputProcessors: [$processor])));
    }

    public function testStreamingOutputProcessorMustPreserveToolUseControlData(): void
    {
        $llmClient = new FakeStreamingLlmClient();
        $agent = $this->createStreamingAgent($llmClient);
        $processor = new class implements OutputProcessorInterface {
            #[Override]
            public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent
            {
                if ($output instanceof StreamEvent && $output->type === StreamEvent::TOOL_USE_DELTA) {
                    return new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"id":2}']);
                }

                return $output;
            }
        };

        $llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"id":1}']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Output processor must preserve stream tool-use control data.');

        iterator_to_array($agent->runStream('Use tool', AgentOptions::withProcessors(outputProcessors: [$processor])));
    }

    private function createAgent(FakeLlmClient $llmClient): Agent
    {
        [$resource, $registry] = $this->resourceAndRegistry();
        $registry->register('article_get', 'app://self/article', 'get');

        return new Agent(
            client: $llmClient,
            dispatcher: new Dispatcher($resource, $registry, new NullToolCallObserver()),
            tools: [$this->articleTool()],
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    private function createStreamingAgent(FakeStreamingLlmClient $llmClient): StreamingAgent
    {
        [$resource, $registry] = $this->resourceAndRegistry();
        $registry->register('article_get', 'app://self/article', 'get');

        return new StreamingAgent(
            client: $llmClient,
            dispatcher: new Dispatcher($resource, $registry, new NullToolCallObserver()),
            tools: [$this->articleTool()],
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    private function createStreamingAgentWithArticleToolAndErrorRegistry(FakeStreamingLlmClient $llmClient): StreamingAgent
    {
        [$resource, $registry] = $this->resourceAndRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $registry->register('error_get', 'app://self/error', 'get');

        return new StreamingAgent(
            client: $llmClient,
            dispatcher: new Dispatcher($resource, $registry, new NullToolCallObserver()),
            tools: [$this->articleTool()],
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    private function createAgentWithArticleAndErrorTools(FakeLlmClient $llmClient): Agent
    {
        [$resource, $registry] = $this->resourceAndRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $registry->register('error_get', 'app://self/error', 'get');

        return new Agent(
            client: $llmClient,
            dispatcher: new Dispatcher($resource, $registry, new NullToolCallObserver()),
            tools: [
                $this->articleTool(),
                new Tool('error_get', 'Get error resource', [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ]),
            ],
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    private function createAgentWithArticleToolAndErrorRegistry(FakeLlmClient $llmClient): Agent
    {
        [$resource, $registry] = $this->resourceAndRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $registry->register('error_get', 'app://self/error', 'get');

        return new Agent(
            client: $llmClient,
            dispatcher: new Dispatcher($resource, $registry, new NullToolCallObserver()),
            tools: [$this->articleTool()],
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    /** @return array{ResourceInterface, ToolRegistry} */
    private function resourceAndRegistry(): array
    {
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));

        return [
            $injector->getInstance(ResourceInterface::class),
            new ToolRegistry(),
        ];
    }

    private function articleTool(): Tool
    {
        return new Tool('article_get', 'Get an article', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ]);
    }
}
