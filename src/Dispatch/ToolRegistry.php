<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use Override;

use function array_keys;
use function sprintf;

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
     * Registering the same mapping again is a no-op, so collecting a resource
     * shared by several subagent profiles stays cheap. A conflicting mapping is
     * rejected: silently overwriting would leave the tool definition the LLM
     * sees pointing at a different resource than the one dispatched.
     *
     * @param string                                       $toolName    Tool name (e.g., "article_get")
     * @param string                                       $resourceUri Resource URI without scheme (e.g., "article")
     * @param string                                       $method      HTTP method (e.g., "get")
     * @param class-string<ToolResultFilterInterface>|null $filter      Filter class
     *
     * @throws DuplicateToolMappingException When the name is mapped to a different resource.
     */
    #[Override]
    public function register(string $toolName, string $resourceUri, string $method, string|null $filter = null): void
    {
        $mapping = new ToolMapping($resourceUri, $method, $filter);
        $registered = $this->mappings[$toolName] ?? null;
        if ($registered !== null && ! $this->isSameMapping($registered, $mapping)) {
            throw new DuplicateToolMappingException(sprintf(
                'Tool "%s" is already mapped to %s (%s). Give one of them a distinct name with #[Tool(name: ...)]',
                $toolName,
                $registered->resourceUri,
                $registered->method,
            ));
        }

        $this->mappings[$toolName] = $mapping;
    }

    private function isSameMapping(ToolMapping $registered, ToolMapping $mapping): bool
    {
        return $registered->resourceUri === $mapping->resourceUri
            && $registered->method === $mapping->method
            && $registered->filter === $mapping->filter;
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
