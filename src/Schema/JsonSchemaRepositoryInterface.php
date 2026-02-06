<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

/**
 * Repository for JSON Schema definitions
 */
interface JsonSchemaRepositoryInterface
{
    /**
     * Get JSON Schema for a resource method's parameters
     *
     * @param class-string $resourceClass Resource class name
     * @param string       $methodName    HTTP method name (onGet, onPost, etc.)
     *
     * @return array<string, mixed>|null Schema properties or null if not found
     */
    public function getParameterSchema(string $resourceClass, string $methodName): array|null;
}
