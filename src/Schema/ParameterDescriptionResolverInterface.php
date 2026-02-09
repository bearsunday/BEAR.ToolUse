<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use ReflectionParameter;

/**
 * Resolves parameter descriptions from PHPDoc or ALPS
 */
interface ParameterDescriptionResolverInterface
{
    /**
     * Get description for a parameter
     *
     * @param ReflectionParameter $param Parameter to get description for
     */
    public function resolve(ReflectionParameter $param): string|null;
}
