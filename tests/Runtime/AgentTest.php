<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

#[CoversClass(Agent::class)]
#[CoversClass(AgentResponse::class)]
#[CoversClass(Message::class)]
final class AgentTest extends TestCase
{
    private Agent $agent;
    private FakeLlmClient $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = new FakeLlmClient();

        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('article_get', 'article', 'get');
        $dispatcher = new Dispatcher($resource, $registry);

        $tools = [
            new Tool('article_get', 'Get an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
        ];

        $this->agent = new Agent(
            client: $this->llmClient,
            dispatcher: $dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    public function testSimpleTextResponse(): void
    {
        $this->llmClient->queueTextResponse('Hello! How can I help you?');

        $response = $this->agent->run('Hello');

        $this->assertTrue($response->completed);
        $this->assertSame('Hello! How can I help you?', $response->getText());
    }

    public function testMaxIterationsReached(): void
    {
        // Queue tool use responses that never complete
        for ($i = 0; $i < 10; $i++) {
            $this->llmClient->queueToolUseResponse('call_' . $i, 'article_get', ['id' => $i]);
        }

        $response = $this->agent->run('Keep calling tools');

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_MAX_ITERATIONS, $response->stopReason);
        $this->assertNotEmpty($response->messages);
    }

    public function testMaxTokensResponse(): void
    {
        $this->llmClient->queueMaxTokensResponse('This response was cut off...');

        $response = $this->agent->run('Write a very long story');

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_MAX_TOKENS, $response->stopReason);
        $this->assertSame('This response was cut off...', $response->getText());
    }

    public function testStopSequenceResponse(): void
    {
        $this->llmClient->queueStopSequenceResponse('Stopped at sequence');

        $response = $this->agent->run('Test stop sequence');

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_STOP_SEQUENCE, $response->stopReason);
    }

    public function testUnknownStopReasonTreatedAsCompleted(): void
    {
        $this->llmClient->queueResponse(new LlmResponse(
            stopReason: 'unknown_reason',
            content: [['type' => 'text', 'text' => 'Unknown stop']],
            toolCalls: [],
        ));

        $response = $this->agent->run('Test unknown stop reason');

        $this->assertTrue($response->completed);
    }

    public function testToolUseWithSuccessfulDispatch(): void
    {
        // First response is tool use
        $this->llmClient->queueToolUseResponse('call_1', 'article_get', ['id' => 123]);
        // Second response is completion
        $this->llmClient->queueTextResponse('I found the article!');

        $response = $this->agent->run('Get article 123');

        $this->assertTrue($response->completed);
        $this->assertSame('I found the article!', $response->getText());
    }

    public function testMessageUser(): void
    {
        $message = Message::user('Hello');

        $this->assertSame('user', $message->role);
        $this->assertSame([['type' => 'text', 'text' => 'Hello']], $message->content);
    }

    public function testMessageAssistant(): void
    {
        $content = [['type' => 'text', 'text' => 'Hi there']];
        $message = Message::assistant($content);

        $this->assertSame('assistant', $message->role);
        $this->assertSame($content, $message->content);
    }

    public function testMessageToArray(): void
    {
        $message = Message::user('Test');
        $array = $message->toArray();

        $this->assertSame('user', $array['role']);
        $this->assertSame([['type' => 'text', 'text' => 'Test']], $array['content']);
    }

    public function testMessageToolResults(): void
    {
        $results = [
            ToolResult::success('call_1', ['data' => 'test']),
            ToolResult::error('call_2', 'Error message'),
        ];
        $message = Message::toolResults($results);

        $this->assertSame('user', $message->role);
        $this->assertCount(2, $message->content);
        $this->assertSame('tool_result', $message->content[0]['type']);
        $this->assertSame('call_1', $message->content[0]['tool_use_id']);
        $this->assertFalse($message->content[0]['is_error']);
        $this->assertTrue($message->content[1]['is_error']);
    }

    public function testAgentReset(): void
    {
        $this->llmClient->queueTextResponse('First response');
        $this->agent->run('First message');

        $this->assertNotEmpty($this->agent->messages);

        $this->agent->reset();

        $this->assertEmpty($this->agent->messages);
    }

    public function testMessagesProperty(): void
    {
        $history = [
            Message::user('Previous question'),
            Message::assistant([['type' => 'text', 'text' => 'Previous answer']]),
        ];

        $this->agent->messages = $history;

        $this->assertCount(2, $this->agent->messages);
        $this->assertSame('user', $this->agent->messages[0]->role);
        $this->assertSame('assistant', $this->agent->messages[1]->role);
    }

    public function testRestoreMessagesAndContinue(): void
    {
        $history = [
            Message::user('Previous question'),
            Message::assistant([['type' => 'text', 'text' => 'Previous answer']]),
        ];

        $this->agent->messages = $history;
        $this->llmClient->queueTextResponse('Continued response');

        $response = $this->agent->run('Follow up question');

        $this->assertTrue($response->completed);
        $this->assertCount(3, $this->agent->messages); // 2 history + 1 new user
    }

    public function testAgentResponseCompleted(): void
    {
        $response = AgentResponse::completed([['type' => 'text', 'text' => 'Done']]);

        $this->assertTrue($response->completed);
        $this->assertSame('Done', $response->getText());
        $this->assertEmpty($response->messages);
    }

    public function testAgentResponseMaxIterations(): void
    {
        $messages = [Message::user('test')];
        $response = AgentResponse::maxIterationsReached($messages);

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_MAX_ITERATIONS, $response->stopReason);
        $this->assertNull($response->content);
        $this->assertCount(1, $response->messages);
    }

    public function testAgentResponseStopReasonCompleted(): void
    {
        $response = AgentResponse::completed([['type' => 'text', 'text' => 'Done']]);

        $this->assertSame(AgentResponse::STOP_COMPLETED, $response->stopReason);
    }

    public function testAgentResponseMaxTokens(): void
    {
        $messages = [Message::user('test')];
        $response = AgentResponse::maxTokensReached([['type' => 'text', 'text' => 'Partial']], $messages);

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_MAX_TOKENS, $response->stopReason);
        $this->assertNotNull($response->content);
    }

    public function testAgentResponseStopSequence(): void
    {
        $messages = [Message::user('test')];
        $response = AgentResponse::stopSequenceReached([['type' => 'text', 'text' => 'Stopped']], $messages);

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_STOP_SEQUENCE, $response->stopReason);
    }

    public function testAgentResponseGetTextWithNonArrayContent(): void
    {
        $response = AgentResponse::completed('plain string');

        $this->assertSame('', $response->getText());
    }

    public function testAgentResponseGetTextWithInvalidBlock(): void
    {
        $response = AgentResponse::completed([
            ['type' => 'image', 'data' => 'base64...'],
            ['type' => 'text', 'text' => 'Valid text'],
            ['type' => 'text'], // missing text field
        ]);

        $this->assertSame('Valid text', $response->getText());
    }

    public function testAgentResponseGetTextWithNonStringText(): void
    {
        $response = AgentResponse::completed([
            ['type' => 'text', 'text' => 123], // non-string text
            ['type' => 'text', 'text' => 'Valid'],
        ]);

        $this->assertSame('Valid', $response->getText());
    }
}
