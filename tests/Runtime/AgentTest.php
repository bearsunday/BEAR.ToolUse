<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Schema\Tool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;

#[CoversClass(Agent::class)]
#[CoversClass(AgentOptions::class)]
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
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

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
        $this->assertCount(2, $this->agent->messages);
        $this->assertSame('user', $this->agent->messages[0]->role);
        $this->assertSame('assistant', $this->agent->messages[1]->role);
        $this->assertSame($this->agent->messages, $response->messages);
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
        $this->assertCount(2, $response->messages);
        $this->assertSame('assistant', $response->messages[1]->role);
    }

    public function testStopSequenceResponse(): void
    {
        $this->llmClient->queueStopSequenceResponse('Stopped at sequence');

        $response = $this->agent->run('Test stop sequence');

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_STOP_SEQUENCE, $response->stopReason);
        $this->assertCount(2, $response->messages);
        $this->assertSame('assistant', $response->messages[1]->role);
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
        $this->assertCount(2, $response->messages);
        $this->assertSame('assistant', $response->messages[1]->role);
    }

    public function testEmptyAssistantResponseIsNotRecorded(): void
    {
        $this->llmClient->queueResponse(new LlmResponse(
            stopReason: 'end_turn',
            content: [],
            toolCalls: [],
        ));

        $response = $this->agent->run('Return nothing');

        $this->assertTrue($response->completed);
        $this->assertCount(1, $this->agent->messages);
        $this->assertSame('user', $this->agent->messages[0]->role);
        $this->assertSame($this->agent->messages, $response->messages);
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

    public function testRunWithToolFilteringPassesOnlyEnabledTools(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgentWithArticleAndErrorTools($llmClient);

        $llmClient->queueTextResponse('Done.');

        $response = $agent->run('Use limited tools', AgentOptions::withTools(['article_get']));

        $this->assertTrue($response->completed);
        $this->assertSame(['article_get'], array_map(
            static fn (Tool $tool): string => $tool->name,
            $llmClient->calls[0]['tools'],
        ));
    }

    public function testRunWithToolFilteringPassesOnlyEnabledToolsAfterToolUse(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgentWithArticleAndErrorTools($llmClient);

        $llmClient->queueToolUseResponse('call_1', 'article_get', ['id' => 1]);
        $llmClient->queueTextResponse('Done.');

        $response = $agent->run('Use limited tools', AgentOptions::withTools(['article_get']));

        $this->assertTrue($response->completed);
        $this->assertCount(2, $llmClient->calls);
        $this->assertSame(['article_get'], array_map(
            static fn (Tool $tool): string => $tool->name,
            $llmClient->calls[1]['tools'],
        ));
    }

    public function testRunWithUnknownToolFilterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool(s): missing_tool');

        $this->agent->run('Use missing tool', AgentOptions::withTools(['missing_tool']));
    }

    public function testRunWithDisabledToolCallReturnsErrorWithoutDispatching(): void
    {
        $llmClient = new FakeLlmClient();
        $agent = $this->createAgentWithArticleAndErrorTools($llmClient);

        $llmClient->queueToolUseResponse('call_1', 'error_get', []);
        $llmClient->queueTextResponse('The disabled tool was not used.');

        $response = $agent->run('Try disabled tool', AgentOptions::withTools(['article_get']));

        $this->assertTrue($response->completed);
        $this->assertSame('The disabled tool was not used.', $response->getText());
        $toolResultMessage = $llmClient->calls[1]['messages'][2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertSame('Tool is not enabled: error_get', $toolResultMessage->content[0]['content']);
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
        $this->assertCount(4, $this->agent->messages); // 2 history + new user + assistant response
    }

    public function testAgentResponseCompleted(): void
    {
        $response = AgentResponse::completed([['type' => 'text', 'text' => 'Done']]);

        $this->assertTrue($response->completed);
        $this->assertSame('Done', $response->getText());
        $this->assertEmpty($response->messages);
    }

    public function testAgentResponseCompletedWithMessages(): void
    {
        $messages = [
            Message::user('test'),
            Message::assistant([['type' => 'text', 'text' => 'Done']]),
        ];
        $response = AgentResponse::completed([['type' => 'text', 'text' => 'Done']], $messages);

        $this->assertTrue($response->completed);
        $this->assertSame($messages, $response->messages);
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

    public function testToolErrorFeedbackLoop(): void
    {
        // Register error tool
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('error_get', 'app://self/error', 'get');
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

        $tools = [
            new Tool('error_get', 'Get error resource', [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ]),
        ];

        $llmClient = new FakeLlmClient();
        $agent = new Agent(
            client: $llmClient,
            dispatcher: $dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );

        // 1st LLM call: returns tool_use → dispatch will fail with exception
        $llmClient->queueToolUseResponse('call_1', 'error_get', []);
        // 2nd LLM call: LLM receives error, responds with text
        $llmClient->queueTextResponse('The tool returned an error. Let me help you differently.');

        $response = $agent->run('Call the error tool');

        // Verify the agent completed after receiving error feedback
        $this->assertTrue($response->completed);
        $this->assertSame('The tool returned an error. Let me help you differently.', $response->getText());

        // Verify the LLM received the error in its 2nd call
        $this->assertCount(2, $llmClient->calls);
        $secondCallMessages = $llmClient->calls[1]['messages'];
        // Messages: [user, assistant(tool_use), user(tool_result with error)]
        $this->assertCount(3, $secondCallMessages);
        $toolResultMessage = $secondCallMessages[2];
        $this->assertSame('user', $toolResultMessage->role);
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertStringContainsString('RuntimeException', $toolResultMessage->content[0]['content']);
    }

    public function testToolErrorFeedbackLoopWithRetry(): void
    {
        // Register both error and success tools
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('error_get', 'app://self/error', 'get');
        $registry->register('article_get', 'app://self/article', 'get');
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

        $tools = [
            new Tool('error_get', 'Get error resource', [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ]),
            new Tool('article_get', 'Get an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
        ];

        $llmClient = new FakeLlmClient();
        $agent = new Agent(
            client: $llmClient,
            dispatcher: $dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );

        // 1st LLM call: tries error tool → fails
        $llmClient->queueToolUseResponse('call_1', 'error_get', []);
        // 2nd LLM call: LLM sees error, retries with different tool
        $llmClient->queueToolUseResponse('call_2', 'article_get', ['id' => 1]);
        // 3rd LLM call: LLM gets success result, returns completion
        $llmClient->queueTextResponse('I found the article for you.');

        $response = $agent->run('Find information');

        // Agent should complete after error → retry → success
        $this->assertTrue($response->completed);
        $this->assertSame('I found the article for you.', $response->getText());

        // Verify 3 LLM calls occurred (initial + error feedback + success feedback)
        $this->assertCount(3, $llmClient->calls);

        // Verify error was fed back in 2nd call
        $secondCallMessages = $llmClient->calls[1]['messages'];
        $toolResultMessage = $secondCallMessages[2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);

        // Verify success was fed back in 3rd call
        $thirdCallMessages = $llmClient->calls[2]['messages'];
        $toolResultMessage = $thirdCallMessages[4];
        $this->assertFalse($toolResultMessage->content[0]['is_error']);
    }

    public function testToolErrorFeedbackLoopWithStatusCode(): void
    {
        // Register status error tool
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('status_error_get', 'app://self/status-error', 'get');
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

        $tools = [
            new Tool('status_error_get', 'Get status error resource', [
                'type' => 'object',
                'properties' => ['code' => ['type' => 'integer']],
                'required' => [],
            ]),
        ];

        $llmClient = new FakeLlmClient();
        $agent = new Agent(
            client: $llmClient,
            dispatcher: $dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );

        // 1st LLM call: tries tool → returns 400 status
        $llmClient->queueToolUseResponse('call_1', 'status_error_get', ['code' => 400]);
        // 2nd LLM call: LLM sees HTTP 400 error, responds with guidance
        $llmClient->queueTextResponse('The request failed with a validation error. The "name" field is required.');

        $response = $agent->run('Call the status error tool');

        $this->assertTrue($response->completed);

        // Verify the LLM received the HTTP status error
        $secondCallMessages = $llmClient->calls[1]['messages'];
        $toolResultMessage = $secondCallMessages[2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertStringContainsString('400:', $toolResultMessage->content[0]['content']);
        $this->assertStringContainsString('Validation failed', $toolResultMessage->content[0]['content']);
    }

    private function createAgentWithArticleAndErrorTools(FakeLlmClient $llmClient): Agent
    {
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $registry->register('error_get', 'app://self/error', 'get');
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

        return new Agent(
            client: $llmClient,
            dispatcher: $dispatcher,
            tools: [
                new Tool('article_get', 'Get an article', [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ]),
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
}
