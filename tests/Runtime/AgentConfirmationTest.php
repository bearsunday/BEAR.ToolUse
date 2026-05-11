<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeConfirmationHandler;
use BEAR\ToolUse\Fake\FakeLlmClient;
use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

#[CoversClass(Agent::class)]
final class AgentConfirmationTest extends TestCase
{
    private FakeLlmClient $llmClient;
    private FakeConfirmationHandler $confirmationHandler;
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->llmClient           = new FakeLlmClient();
        $this->confirmationHandler = new FakeConfirmationHandler();

        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $registry->register('article_delete', 'app://self/article', 'delete');
        $this->dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());
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

        $agent = new Agent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
            confirmationHandler: $this->confirmationHandler,
        );

        $this->confirmationHandler->setWillConfirm(true);
        $this->llmClient->queueToolUseWithTextResponse(
            'call_1',
            'article_delete',
            ['id' => 123],
            'I will delete article 123.',
        );
        $this->llmClient->queueTextResponse('Article 123 has been deleted.');

        $response = $agent->run('Delete article 123');

        $this->assertTrue($response->completed);
        $this->assertSame('Article 123 has been deleted.', $response->getText());
        $this->assertCount(1, $this->confirmationHandler->calls);
        $this->assertSame('article_delete', $this->confirmationHandler->calls[0]['toolCall']->name);
        $this->assertSame('I will delete article 123.', $this->confirmationHandler->calls[0]['llmText']);
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

        $agent = new Agent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
            confirmationHandler: $this->confirmationHandler,
        );

        $this->confirmationHandler->setWillConfirm(false);
        $this->llmClient->queueToolUseWithTextResponse(
            'call_1',
            'article_delete',
            ['id' => 123],
            'I will delete article 123.',
        );
        $this->llmClient->queueTextResponse('Understood, I will not delete the article.');

        $response = $agent->run('Delete article 123');

        $this->assertTrue($response->completed);
        $this->assertSame('Understood, I will not delete the article.', $response->getText());

        // Verify the LLM received the cancellation error
        $secondCallMessages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage  = $secondCallMessages[2];
        $this->assertTrue($toolResultMessage->content[0]['is_error']);
        $this->assertSame('User cancelled this operation.', $toolResultMessage->content[0]['content']);
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

        $agent = new Agent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
            confirmationHandler: $this->confirmationHandler,
        );

        // article_get has confirm: false, should not trigger confirmation
        $this->llmClient->queueToolUseResponse('call_1', 'article_get', ['id' => 1]);
        $this->llmClient->queueTextResponse('Here is the article.');

        $response = $agent->run('Get article 1');

        $this->assertTrue($response->completed);
        $this->assertEmpty($this->confirmationHandler->calls);
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

        // No confirmation handler → tool executes without confirmation
        $agent = new Agent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );

        $this->llmClient->queueToolUseResponse('call_1', 'article_delete', ['id' => 1]);
        $this->llmClient->queueTextResponse('Deleted.');

        $response = $agent->run('Delete article 1');

        $this->assertTrue($response->completed);
        $this->assertSame('Deleted.', $response->getText());
    }

    public function testConfirmationWithToolUseWithoutText(): void
    {
        $tools = [
            new Tool('article_delete', 'Delete an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ], confirm: true),
        ];

        $agent = new Agent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
            confirmationHandler: $this->confirmationHandler,
        );

        // tool_use response without text block
        $this->llmClient->queueToolUseResponse('call_1', 'article_delete', ['id' => 1]);
        $this->llmClient->queueTextResponse('Done.');

        $response = $agent->run('Delete article 1');

        $this->assertTrue($response->completed);
        // Handler receives empty text when no text block exists
        $this->assertSame('', $this->confirmationHandler->calls[0]['llmText']);
    }
}
