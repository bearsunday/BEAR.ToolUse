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

use function count;

#[CoversClass(Agent::class)]
#[CoversClass(AgentResponse::class)]
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

    public function testResetClearsPendingToolResults(): void
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

        // Held server results are gone: resume() only carries the given results
        $this->agent->resume([ToolResult::success('call_client', 'applied')]);
        $messages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage = $messages[count($messages) - 1];
        $this->assertCount(1, $toolResultMessage->content);
        $this->assertSame('call_client', $toolResultMessage->content[0]['tool_use_id']);
    }
}
