<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use ReflectionParameter;

/**
 * Resolves parameter descriptions from various sources
 */
interface ParameterDescriptionResolverInterface
{
    /**
     * Get description for a parameter
     *
     * @param ReflectionParameter       $param      Parameter to get description for
     * @param array<string, mixed>|null $jsonSchema JSON Schema properties
     */
    public function resolve(ReflectionParameter $param, array|null $jsonSchema): string|null;
}
