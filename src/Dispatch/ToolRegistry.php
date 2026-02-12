<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use Override;

use function array_key_exists;
use function array_keys;

/**
 * Registry for tool name to resource mapping
 *
 * @psalm-type ToolMapping = array{resourceUri: string, method: string, filter?: class-string<ToolResultFilterInterface>}
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
        $mapping = [
            'resourceUri' => $resourceUri,
            'method' => $method,
        ];

        if ($filter !== null) {
            $mapping['filter'] = $filter;
        }

        $this->mappings[$toolName] = $mapping;
    }

    /**
     * Get mapping for a tool name
     *
     * @return ToolMapping|null
     */
    #[Override]
    public function get(string $toolName): array|null
    {
        if (! array_key_exists($toolName, $this->mappings)) {
            return null;
        }

        return $this->mappings[$toolName];
    }

    #[Override]
    public function has(string $toolName): bool
    {
        return array_key_exists($toolName, $this->mappings);
    }

    /** @return list<string> */
    #[Override]
    public function getToolNames(): array
    {
        return array_keys($this->mappings);
    }
}
