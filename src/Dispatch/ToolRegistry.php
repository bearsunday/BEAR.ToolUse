<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use Override;

use function array_keys;

/**
 * Registry for tool name to resource mapping
 */
final class ToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, ToolMapping> */
    private array $mappings = [];

    /**
     * Register a tool mapping
     *
     * @param string                                       $toolName    Tool name (e.g., "article_get")
     * @param string                                       $resourceUri Resource URI without scheme (e.g., "article")
     * @param string                                       $method      HTTP method (e.g., "get")
     * @param class-string<ToolResultFilterInterface>|null $filter      Filter class
     */
    #[Override]
    public function register(string $toolName, string $resourceUri, string $method, string|null $filter = null): void
    {
        $this->mappings[$toolName] = new ToolMapping($resourceUri, $method, $filter);
    }

    #[Override]
    public function get(string $toolName): ToolMapping|null
    {
        return $this->mappings[$toolName] ?? null;
    }

    #[Override]
    public function has(string $toolName): bool
    {
        return isset($this->mappings[$toolName]);
    }

    /** @return list<string> */
    #[Override]
    public function getToolNames(): array
    {
        return array_keys($this->mappings);
    }
}
