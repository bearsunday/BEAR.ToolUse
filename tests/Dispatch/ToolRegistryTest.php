<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\ToolUse\Fake\FakeSummaryFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolRegistry::class)]
#[CoversClass(ToolMapping::class)]
final class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    public function testRegisterAndGet(): void
    {
        $this->registry->register('article_get', 'article', 'get');

        $mapping = $this->registry->get('article_get');

        $this->assertNotNull($mapping);
        $this->assertSame('article', $mapping->resourceUri);
        $this->assertSame('get', $mapping->method);
    }

    public function testHas(): void
    {
        $this->registry->register('article_get', 'article', 'get');

        $this->assertTrue($this->registry->has('article_get'));
        $this->assertFalse($this->registry->has('unknown_tool'));
    }

    public function testGetUnknown(): void
    {
        $mapping = $this->registry->get('unknown_tool');

        $this->assertNull($mapping);
    }

    public function testGetToolNames(): void
    {
        $this->registry->register('article_get', 'article', 'get');
        $this->registry->register('user_post', 'user', 'post');

        $names = $this->registry->getToolNames();

        $this->assertCount(2, $names);
        $this->assertContains('article_get', $names);
        $this->assertContains('user_post', $names);
    }

    public function testRegisterWithFilter(): void
    {
        $this->registry->register('search_get', 'search', 'get', FakeSummaryFilter::class);

        $mapping = $this->registry->get('search_get');

        $this->assertNotNull($mapping);
        $this->assertSame('search', $mapping->resourceUri);
        $this->assertSame('get', $mapping->method);
        $this->assertSame(FakeSummaryFilter::class, $mapping->filter);
    }

    public function testRegisterWithoutFilterDefaultsToNull(): void
    {
        $this->registry->register('article_get', 'article', 'get');

        $mapping = $this->registry->get('article_get');

        $this->assertNotNull($mapping);
        $this->assertNull($mapping->filter);
    }
}
