<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

/**
 * Registry for tool name to resource mapping
 *
 * @psalm-type ToolMapping = array{resourceUri: string, method: string}
 */
interface ToolRegistryInterface
{
    /**
     * Register a tool mapping
     *
     * @param string $toolName    Tool name (e.g., "article_get")
     * @param string $resourceUri Resource URI (e.g., "app://self/article" or "article")
     * @param string $method      HTTP method (e.g., "get")
     */
    public function register(string $toolName, string $resourceUri, string $method): void;

    /**
     * Get mapping for a tool name
     *
     * @return ToolMapping|null
     */
    public function get(string $toolName): array|null;

    /**
     * Check if a tool is registered
     */
    public function has(string $toolName): bool;

    /**
     * Get all registered tool names
     *
     * @return list<string>
     */
    public function getToolNames(): array;
}
