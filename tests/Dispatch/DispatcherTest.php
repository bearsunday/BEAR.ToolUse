<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Fake\FakeSummaryFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

#[CoversClass(Dispatcher::class)]
#[CoversClass(ToolCall::class)]
#[CoversClass(ToolResult::class)]
final class DispatcherTest extends TestCase
{
    private Dispatcher $dispatcher;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);

        $this->registry = new ToolRegistry();
        $this->dispatcher = new Dispatcher($resource, $this->registry);
    }

    public function testDispatchUnknownTool(): void
    {
        $toolCall = new ToolCall('call_123', 'unknown_tool', []);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unknown tool', $result->content);
    }

    public function testDispatchGet(): void
    {
        $this->registry->register('crud_get', 'app://self/crud', 'get');
        $toolCall = new ToolCall('call_get', 'crud_get', ['id' => 1]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
        $this->assertSame('call_get', $result->toolUseId);
        $this->assertStringContainsString('"method":"GET"', $result->content);
    }

    public function testDispatchPost(): void
    {
        $this->registry->register('crud_post', 'app://self/crud', 'post');
        $toolCall = new ToolCall('call_post', 'crud_post', ['name' => 'test']);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('"method":"POST"', $result->content);
    }

    public function testDispatchPut(): void
    {
        $this->registry->register('crud_put', 'app://self/crud', 'put');
        $toolCall = new ToolCall('call_put', 'crud_put', ['id' => 1, 'name' => 'updated']);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('"method":"PUT"', $result->content);
    }

    public function testDispatchPatch(): void
    {
        $this->registry->register('crud_patch', 'app://self/crud', 'patch');
        $toolCall = new ToolCall('call_patch', 'crud_patch', ['id' => 1, 'name' => 'patched']);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('"method":"PATCH"', $result->content);
    }

    public function testDispatchDelete(): void
    {
        $this->registry->register('crud_delete', 'app://self/crud', 'delete');
        $toolCall = new ToolCall('call_delete', 'crud_delete', ['id' => 1]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('"method":"DELETE"', $result->content);
    }

    public function testDispatchDefaultMethod(): void
    {
        $this->registry->register('crud_unknown', 'app://self/crud', 'unknown');
        $toolCall = new ToolCall('call_unknown', 'crud_unknown', ['id' => 1]);

        $result = $this->dispatcher->dispatch($toolCall);

        // Default falls back to GET
        $this->assertFalse($result->isError);
        $this->assertStringContainsString('"method":"GET"', $result->content);
    }

    public function testDispatchWithException(): void
    {
        $this->registry->register('error_get', 'app://self/error', 'get');
        $toolCall = new ToolCall('call_error', 'error_get', []);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('RuntimeException', $result->content);
        $this->assertStringContainsString('Test error', $result->content);
    }

    public function testToolCallFromArray(): void
    {
        $toolCall = ToolCall::fromArray([
            'id' => 'call_456',
            'name' => 'article_get',
            'input' => ['id' => 1],
        ]);

        $this->assertSame('call_456', $toolCall->id);
        $this->assertSame('article_get', $toolCall->name);
        $this->assertSame(['id' => 1], $toolCall->input);
    }

    public function testToolCallFromArrayWithoutInput(): void
    {
        $toolCall = ToolCall::fromArray([
            'id' => 'call_789',
            'name' => 'index_get',
        ]);

        $this->assertSame([], $toolCall->input);
    }

    public function testToolResultSuccess(): void
    {
        $result = ToolResult::success('call_123', ['data' => 'test']);

        $this->assertFalse($result->isError);
        $this->assertSame('call_123', $result->toolUseId);
        $this->assertSame(['data' => 'test'], $result->content);
    }

    public function testToolResultError(): void
    {
        $result = ToolResult::error('call_123', 'Something went wrong');

        $this->assertTrue($result->isError);
        $this->assertSame('call_123', $result->toolUseId);
        $this->assertSame('Something went wrong', $result->content);
    }

    public function testDispatchWithDifferentScheme(): void
    {
        $this->registry->register('page_article_get', 'page://self/article', 'get');
        $toolCall = new ToolCall('call_page', 'page_article_get', ['id' => 1]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
    }

    public function testDispatchWithErrorStatusCode(): void
    {
        $this->registry->register('status_error_get', 'app://self/status-error', 'get');
        $toolCall = new ToolCall('call_status', 'status_error_get', ['code' => 400]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('400:', $result->content);
        $this->assertStringContainsString('Validation failed', $result->content);
    }

    public function testDispatchWithServerErrorStatusCode(): void
    {
        $this->registry->register('status_error_get', 'app://self/status-error', 'get');
        $toolCall = new ToolCall('call_status', 'status_error_get', ['code' => 500]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('500:', $result->content);
    }

    public function testDispatchWithSuccessStatusCode(): void
    {
        $this->registry->register('crud_get', 'app://self/crud', 'get');
        $toolCall = new ToolCall('call_success', 'crud_get', ['id' => 1]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
    }

    public function testDispatchWithFilter(): void
    {
        $this->registry->register('filtered_get', 'app://self/filtered', 'get', FakeSummaryFilter::class);
        $toolCall = new ToolCall('call_filter', 'filtered_get', ['id' => 1]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('"id":1', $result->content);
        $this->assertStringContainsString('"title":"Article 1"', $result->content);
        $this->assertStringNotContainsString('body', $result->content);
        $this->assertStringNotContainsString('metadata', $result->content);
    }

    public function testDispatchWithFilterNotAppliedOnError(): void
    {
        $this->registry->register('status_error_get', 'app://self/status-error', 'get', FakeSummaryFilter::class);
        $toolCall = new ToolCall('call_filter_error', 'status_error_get', ['code' => 400]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('400:', $result->content);
    }

    public function testDispatchWithoutFilter(): void
    {
        $this->registry->register('filtered_get', 'app://self/filtered', 'get');
        $toolCall = new ToolCall('call_no_filter', 'filtered_get', ['id' => 1]);

        $result = $this->dispatcher->dispatch($toolCall);

        $this->assertFalse($result->isError);
        // Without filter, body text should be present
        $this->assertStringContainsString('Long body text', $result->content);
    }
}
