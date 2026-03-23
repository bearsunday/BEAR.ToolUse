<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeConfirmationHandler;
use BEAR\ToolUse\Fake\FakeStreamingLlmClient;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function iterator_to_array;
use function json_encode;

#[CoversClass(StreamingAgent::class)]
final class StreamingAgentConfirmationTest extends TestCase
{
    private FakeStreamingLlmClient $llmClient;
    private FakeConfirmationHandler $confirmationHandler;
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->llmClient           = new FakeStreamingLlmClient();
        $this->confirmationHandler = new FakeConfirmationHandler();

        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $registry->register('article_delete', 'app://self/article', 'delete');
        $this->dispatcher = new Dispatcher($resource, $registry);
    }

    public function testConfirmationApproved(): void
    {
        $tools = [
            new Tool('article_delete', 'Delete an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ], confirm: true),
        ];

        $agent = new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
            confirmationHandler: $this->confirmationHandler,
        );

        $this->confirmationHandler->setWillConfirm(true);
        $toolInput = json_encode(['id' => 123]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'I will delete article 123.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Article 123 has been deleted.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($agent->runStream('Delete article 123'));

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertContains(AgentEvent::TOOL_RESULT, $types);
        self::assertCount(1, $this->confirmationHandler->calls);
        self::assertSame('article_delete', $this->confirmationHandler->calls[0]['toolCall']->name);
        self::assertSame('I will delete article 123.', $this->confirmationHandler->calls[0]['llmText']);
    }

    public function testConfirmationDenied(): void
    {
        $tools = [
            new Tool('article_delete', 'Delete an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ], confirm: true),
        ];

        $agent = new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
            confirmationHandler: $this->confirmationHandler,
        );

        $this->confirmationHandler->setWillConfirm(false);
        $toolInput = json_encode(['id' => 123]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'I will delete article 123.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Understood, I will not delete the article.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($agent->runStream('Delete article 123'));

        // Verify the LLM received the cancellation error
        $secondCallMessages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage  = $secondCallMessages[2];
        self::assertTrue($toolResultMessage->content[0]['is_error']);
        self::assertSame('User cancelled this operation.', $toolResultMessage->content[0]['content']);
    }

    public function testNonConfirmableToolExecutedWithoutConfirmation(): void
    {
        $tools = [
            new Tool('article_get', 'Get an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
            new Tool('article_delete', 'Delete an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ], confirm: true),
        ];

        $agent = new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
            confirmationHandler: $this->confirmationHandler,
        );

        $toolInput = json_encode(['id' => 1]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Here is the article.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($agent->runStream('Get article 1'));

        self::assertEmpty($this->confirmationHandler->calls);
    }

    public function testConfirmableToolWithoutHandlerExecutesNormally(): void
    {
        $tools = [
            new Tool('article_delete', 'Delete an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ], confirm: true),
        ];

        // No confirmation handler
        $agent = new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );

        $toolInput = json_encode(['id' => 1]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Deleted.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($agent->runStream('Delete article 1'));

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertContains(AgentEvent::TOOL_RESULT, $types);
        self::assertContains(AgentEvent::COMPLETED, $types);
    }
}
