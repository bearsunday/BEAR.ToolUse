<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Fake\FakeStreamingLlmClient;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Schema\Tool;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function count;
use function end;
use function iterator_to_array;
use function json_encode;

#[CoversClass(StreamingAgent::class)]
#[CoversClass(AgentEvent::class)]
#[CoversClass(ResumeValidator::class)]
#[CoversClass(ToolList::class)]
final class StreamingAgentClientToolTest extends TestCase
{
    private StreamingAgent $agent;
    private FakeStreamingLlmClient $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = new FakeStreamingLlmClient();

        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

        $tools = [
            new Tool('article_get', 'Get an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
            new Tool('ui_update', 'Update a form field on the client', [
                'type' => 'object',
                'properties' => [
                    'field' => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                ],
                'required' => ['field', 'value'],
            ], client: true),
        ];

        $this->agent = new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    public function testClientToolCallEndsStream(): void
    {
        $toolInput = json_encode(['field' => 'title', 'value' => 'New']);
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'ui_update']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Update the title'));

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        $this->assertSame([AgentEvent::TOOL_START, AgentEvent::CLIENT_TOOL_CALL], $types);
        $this->assertSame('ui_update', $events[1]->data['toolName']);
        $this->assertSame('call_1', $events[1]->data['toolId']);
        $this->assertSame(['field' => 'title', 'value' => 'New'], $events[1]->data['input']);
        $this->assertCount(1, $this->llmClient->calls);
        // No tool results message is appended while awaiting the client
        $lastMessage = $this->agent->messages[count($this->agent->messages) - 1];
        $this->assertSame('assistant', $lastMessage->role);
    }

    public function testResumeStreamContinuesAfterClientExecution(): void
    {
        $toolInput = json_encode(['field' => 'title', 'value' => 'New']);
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'ui_update']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'The title has been updated.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('Update the title'));

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->resumeStream([ToolResult::success('call_1', ['applied' => true])]));

        /** @var AgentEvent $lastEvent */
        $lastEvent = end($events);
        $this->assertSame(AgentEvent::COMPLETED, $lastEvent->type);
        $this->assertSame('The title has been updated.', $lastEvent->data['fullText']);

        // The second LLM call receives the client tool result as a tool_result message
        $messages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage = $messages[count($messages) - 1];
        $this->assertSame('user', $toolResultMessage->role);
        $this->assertSame('tool_result', $toolResultMessage->content[0]['type']);
        $this->assertSame('call_1', $toolResultMessage->content[0]['tool_use_id']);
    }

    public function testMixedServerAndClientCallsMergeOnResume(): void
    {
        $serverInput = json_encode(['id' => 123]);
        $clientInput = json_encode(['field' => 'title', 'value' => 'T']);
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_server', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $serverInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_client', 'name' => 'ui_update']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $clientInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Done.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Get article 123 and update the title'));

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        $this->assertSame([
            AgentEvent::TOOL_START,
            AgentEvent::TOOL_START,
            AgentEvent::TOOL_RESULT,
            AgentEvent::CLIENT_TOOL_CALL,
        ], $types);
        $this->assertSame('article_get', $events[2]->data['toolName']);
        $this->assertSame('ui_update', $events[3]->data['toolName']);

        iterator_to_array($this->agent->resumeStream([ToolResult::success('call_client', 'applied')]));

        // Held server result and client result are merged into a single tool results message
        $messages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage = $messages[count($messages) - 1];
        $this->assertSame('user', $toolResultMessage->role);
        $this->assertCount(2, $toolResultMessage->content);
        $this->assertSame('call_server', $toolResultMessage->content[0]['tool_use_id']);
        $this->assertFalse($toolResultMessage->content[0]['is_error']);
        $this->assertSame('call_client', $toolResultMessage->content[1]['tool_use_id']);
    }

    public function testResumeStreamAfterResetIsRejected(): void
    {
        $serverInput = json_encode(['id' => 123]);
        $clientInput = json_encode(['field' => 'title', 'value' => 'T']);
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_server', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $serverInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_client', 'name' => 'ui_update']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $clientInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('Get article 123 and update the title'));
        $this->agent->reset();

        $this->assertSame([], $this->agent->messages);

        // No client tool call is awaiting results after reset(); the
        // validation throws eagerly, before any stream is started
        $this->expectException(InvalidResumeException::class);

        $this->agent->resumeStream([ToolResult::success('call_client', 'applied')]);
    }

    public function testResumeStreamWithMismatchedResultIsRejected(): void
    {
        $toolInput = json_encode(['field' => 'title', 'value' => 'New']);
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'ui_update']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('Update the title'));

        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('missing: [call_1], unexpected: [call_other]');

        $this->agent->resumeStream([ToolResult::success('call_other', 'applied')]);
    }

    public function testMalformedClientToolInputThrows(): void
    {
        // Malformed tool arguments must not silently degrade to an empty
        // input and reach the client as an executable call
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'ui_update']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"field": "title", ']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
        ]);

        $this->expectException(JsonException::class);

        iterator_to_array($this->agent->runStream('Update the title'));
    }

    public function testStatelessResumeStreamOnFreshAgent(): void
    {
        // A consumer resuming across HTTP requests reconstructs the
        // conversation on a fresh agent instance and resumes from there
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'The title has been updated.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        $this->agent->messages[] = Message::user('Update the title');
        $this->agent->messages[] = Message::assistant([
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'New']],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->resumeStream([ToolResult::success('call_1', ['applied' => true])]));

        /** @var AgentEvent $lastEvent */
        $lastEvent = end($events);
        $this->assertSame(AgentEvent::COMPLETED, $lastEvent->type);
        $this->assertSame('The title has been updated.', $lastEvent->data['fullText']);
    }
}
