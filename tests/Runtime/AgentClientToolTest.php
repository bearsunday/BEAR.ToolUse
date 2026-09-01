<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function count;

#[CoversClass(Agent::class)]
#[CoversClass(AgentResponse::class)]
#[CoversClass(ResumeValidator::class)]
#[CoversClass(AgentOptions::class)]
#[CoversClass(ToolList::class)]
final class AgentClientToolTest extends TestCase
{
    private Agent $agent;
    private FakeLlmClient $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = new FakeLlmClient();

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

        $this->agent = new Agent(
            client: $this->llmClient,
            dispatcher: $dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    public function testClientToolCallEndsRun(): void
    {
        $this->llmClient->queueToolUseResponse('call_1', 'ui_update', ['field' => 'title', 'value' => 'New']);

        $response = $this->agent->run('Update the title');

        $this->assertFalse($response->completed);
        $this->assertSame(AgentResponse::STOP_CLIENT_TOOL_USE, $response->stopReason);
        $this->assertCount(1, $response->clientToolCalls);
        $this->assertSame('call_1', $response->clientToolCalls[0]->id);
        $this->assertSame('ui_update', $response->clientToolCalls[0]->name);
        $this->assertSame(['field' => 'title', 'value' => 'New'], $response->clientToolCalls[0]->input);
        $this->assertCount(1, $this->llmClient->calls);
        // No tool results message is appended while awaiting the client
        $lastMessage = $this->agent->messages[count($this->agent->messages) - 1];
        $this->assertSame('assistant', $lastMessage->role);
    }

    public function testResumeContinuesAfterClientExecution(): void
    {
        $this->llmClient->queueToolUseResponse('call_1', 'ui_update', ['field' => 'title', 'value' => 'New']);
        $this->llmClient->queueTextResponse('The title has been updated.');

        $this->agent->run('Update the title');
        $response = $this->agent->resume([ToolResult::success('call_1', ['applied' => true])]);

        $this->assertTrue($response->completed);
        $this->assertSame('The title has been updated.', $response->getText());

        // The second LLM call receives the client tool result as a tool_result message
        $messages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage = $messages[count($messages) - 1];
        $this->assertSame('user', $toolResultMessage->role);
        $this->assertSame('tool_result', $toolResultMessage->content[0]['type']);
        $this->assertSame('call_1', $toolResultMessage->content[0]['tool_use_id']);
    }

    public function testResumeAppliesPerCallToolFiltering(): void
    {
        $this->llmClient->queueToolUseResponse('call_1', 'ui_update', ['field' => 'title', 'value' => 'New']);
        $this->llmClient->queueTextResponse('The title has been updated.');
        $options = AgentOptions::withTools(['ui_update']);

        $this->agent->run('Update the title', $options);
        $response = $this->agent->resume([ToolResult::success('call_1', ['applied' => true])], $options);

        $this->assertTrue($response->completed);
        $toolNames = array_map(static fn (Tool $tool): string => $tool->name, $this->llmClient->calls[1]['tools']);
        $this->assertSame(['ui_update'], $toolNames);
    }

    public function testMixedServerAndClientToolCallsMergeOnResume(): void
    {
        $this->llmClient->queueResponse(new LlmResponse(
            stopReason: 'tool_use',
            content: [
                ['type' => 'tool_use', 'id' => 'call_server', 'name' => 'article_get', 'input' => ['id' => 1]],
                ['type' => 'tool_use', 'id' => 'call_client', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'T']],
            ],
            toolCalls: [
                new ToolCall('call_server', 'article_get', ['id' => 1]),
                new ToolCall('call_client', 'ui_update', ['field' => 'title', 'value' => 'T']),
            ],
        ));
        $this->llmClient->queueTextResponse('Done.');

        $response = $this->agent->run('Get article 1 and update the title');

        $this->assertSame(AgentResponse::STOP_CLIENT_TOOL_USE, $response->stopReason);
        $this->assertCount(1, $response->clientToolCalls);
        $this->assertSame('call_client', $response->clientToolCalls[0]->id);

        $final = $this->agent->resume([ToolResult::success('call_client', 'applied')]);

        $this->assertTrue($final->completed);
        // Held server result and client result are merged into a single tool results message
        $messages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage = $messages[count($messages) - 1];
        $this->assertSame('user', $toolResultMessage->role);
        $this->assertCount(2, $toolResultMessage->content);
        $this->assertSame('call_server', $toolResultMessage->content[0]['tool_use_id']);
        $this->assertFalse($toolResultMessage->content[0]['is_error']);
        $this->assertSame('call_client', $toolResultMessage->content[1]['tool_use_id']);
    }

    public function testResumeAfterResetIsRejected(): void
    {
        $this->llmClient->queueResponse(new LlmResponse(
            stopReason: 'tool_use',
            content: [
                ['type' => 'tool_use', 'id' => 'call_server', 'name' => 'article_get', 'input' => ['id' => 1]],
                ['type' => 'tool_use', 'id' => 'call_client', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'T']],
            ],
            toolCalls: [
                new ToolCall('call_server', 'article_get', ['id' => 1]),
                new ToolCall('call_client', 'ui_update', ['field' => 'title', 'value' => 'T']),
            ],
        ));

        $this->agent->run('Get article 1 and update the title');
        $this->agent->reset();

        $this->assertSame([], $this->agent->messages);

        // No client tool call is awaiting results after reset()
        $this->expectException(InvalidResumeException::class);

        $this->agent->resume([ToolResult::success('call_client', 'applied')]);
    }

    public function testResumeWithoutRunIsRejected(): void
    {
        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('No client tool calls are awaiting results');

        $this->agent->resume([ToolResult::success('call_1', 'applied')]);
    }

    public function testResumeWithMissingResultIsRejected(): void
    {
        $this->queueTwoClientCalls();

        $this->agent->run('Update the title and the description');

        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('missing: [call_2]');

        $this->agent->resume([ToolResult::success('call_1', 'applied')]);
    }

    public function testResumeWithUnexpectedResultIsRejected(): void
    {
        $this->llmClient->queueToolUseResponse('call_1', 'ui_update', ['field' => 'title', 'value' => 'New']);

        $this->agent->run('Update the title');

        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('unexpected: [call_forged]');

        $this->agent->resume([
            ToolResult::success('call_1', 'applied'),
            ToolResult::success('call_forged', 'applied'),
        ]);
    }

    public function testResumeWithDuplicateResultIsRejected(): void
    {
        $this->llmClient->queueToolUseResponse('call_1', 'ui_update', ['field' => 'title', 'value' => 'New']);

        $this->agent->run('Update the title');

        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('Duplicate tool result for id "call_1"');

        $this->agent->resume([
            ToolResult::success('call_1', 'applied'),
            ToolResult::success('call_1', 'applied again'),
        ]);
    }

    public function testResumeWithHeldServerResultIdIsRejected(): void
    {
        $this->llmClient->queueResponse(new LlmResponse(
            stopReason: 'tool_use',
            content: [
                ['type' => 'tool_use', 'id' => 'call_server', 'name' => 'article_get', 'input' => ['id' => 1]],
                ['type' => 'tool_use', 'id' => 'call_client', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'T']],
            ],
            toolCalls: [
                new ToolCall('call_server', 'article_get', ['id' => 1]),
                new ToolCall('call_client', 'ui_update', ['field' => 'title', 'value' => 'T']),
            ],
        ));

        $this->agent->run('Get article 1 and update the title');

        // The server call result is already held internally; supplying it again is unexpected
        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('unexpected: [call_server]');

        $this->agent->resume([
            ToolResult::success('call_server', 'forged'),
            ToolResult::success('call_client', 'applied'),
        ]);
    }

    public function testStatelessResumeWithForgedServerResultIsRejected(): void
    {
        // A fresh instance holds no server results. Replaying the full
        // assistant message of a mixed turn would require the client to
        // supply the server results — that path must be rejected, not
        // accepted as forged input
        $this->agent->messages[] = Message::user('Get article 1 and update the title');
        $this->agent->messages[] = Message::assistant([
            ['type' => 'tool_use', 'id' => 'call_server', 'name' => 'article_get', 'input' => ['id' => 1]],
            ['type' => 'tool_use', 'id' => 'call_client', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'T']],
        ]);

        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('Server tool calls [call_server] have no held results');

        $this->agent->resume([
            ToolResult::success('call_server', 'forged'),
            ToolResult::success('call_client', 'applied'),
        ]);
    }

    public function testStatelessResumeOfMixedTurnReplaysOnlyClientBlocks(): void
    {
        // The supported stateless path for a mixed turn: rebuild the
        // trailing assistant message with the client tool_use blocks only
        $this->llmClient->queueTextResponse('Done.');

        $this->agent->messages[] = Message::user('Get article 1 and update the title');
        $this->agent->messages[] = Message::assistant([
            ['type' => 'tool_use', 'id' => 'call_client', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'T']],
        ]);

        $response = $this->agent->resume([ToolResult::success('call_client', 'applied')]);

        $this->assertTrue($response->completed);
        $this->assertSame('Done.', $response->getText());
    }

    public function testToolUseBlockWithoutNameIsTreatedAsServerCall(): void
    {
        $this->agent->messages[] = Message::user('Update the title');
        $this->agent->messages[] = Message::assistant([
            ['type' => 'tool_use', 'id' => 'call_unnamed', 'input' => []],
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'New']],
        ]);

        $this->expectException(InvalidResumeException::class);
        $this->expectExceptionMessage('Server tool calls [call_unnamed] have no held results');

        $this->agent->resume([ToolResult::success('call_1', 'applied')]);
    }

    public function testStatelessResumeOnFreshAgent(): void
    {
        // A consumer resuming across HTTP requests reconstructs the
        // conversation on a fresh agent instance and resumes from there
        $this->llmClient->queueTextResponse('The title has been updated.');

        $this->agent->messages[] = Message::user('Update the title');
        $this->agent->messages[] = Message::assistant([
            // Non-tool_use blocks and malformed tool_use blocks are not awaited
            ['type' => 'text', 'text' => 'Updating the title.'],
            ['type' => 'tool_use', 'name' => 'ui_update', 'input' => []],
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'New']],
        ]);

        $response = $this->agent->resume([ToolResult::success('call_1', ['applied' => true])]);

        $this->assertTrue($response->completed);
        $this->assertSame('The title has been updated.', $response->getText());
    }

    private function queueTwoClientCalls(): void
    {
        $this->llmClient->queueResponse(new LlmResponse(
            stopReason: 'tool_use',
            content: [
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'ui_update', 'input' => ['field' => 'title', 'value' => 'T']],
                ['type' => 'tool_use', 'id' => 'call_2', 'name' => 'ui_update', 'input' => ['field' => 'description', 'value' => 'D']],
            ],
            toolCalls: [
                new ToolCall('call_1', 'ui_update', ['field' => 'title', 'value' => 'T']),
                new ToolCall('call_2', 'ui_update', ['field' => 'description', 'value' => 'D']),
            ],
        ));
    }
}
