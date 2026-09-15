<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\ToolUse\Fake\FakeSummaryFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolRegistry::class)]
#[CoversClass(ToolMapping::class)]
#[CoversClass(DuplicateToolMappingException::class)]
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

    public function testRegisteringTheSameMappingAgainIsANoOp(): void
    {
        $this->registry->register('article_get', 'app://self/article', 'get');
        $this->registry->register('article_get', 'app://self/article', 'get');

        $mapping = $this->registry->get('article_get');

        $this->assertNotNull($mapping);
        $this->assertSame('app://self/article', $mapping->resourceUri);
    }

    public function testConflictingMappingIsRejected(): void
    {
        $this->registry->register('article_get', 'app://self/article', 'get');

        try {
            $this->registry->register('article_get', 'page://self/article', 'get');
            $this->fail('Expected DuplicateToolMappingException');
        } catch (DuplicateToolMappingException $e) {
            $this->assertStringContainsString('already mapped to app://self/article', $e->getMessage());
        }

        // The first mapping stays: the tool the LLM was shown is the one dispatched
        $mapping = $this->registry->get('article_get');
        $this->assertNotNull($mapping);
        $this->assertSame('app://self/article', $mapping->resourceUri);
    }

    public function testConflictingFilterIsRejected(): void
    {
        $this->registry->register('search_get', 'app://self/search', 'get');

        $this->expectException(DuplicateToolMappingException::class);

        $this->registry->register('search_get', 'app://self/search', 'get', FakeSummaryFilter::class);
    }

    public function testRegisterWithoutFilterDefaultsToNull(): void
    {
        $this->registry->register('article_get', 'article', 'get');

        $mapping = $this->registry->get('article_get');

        $this->assertNotNull($mapping);
        $this->assertNull($mapping->filter);
    }
}
