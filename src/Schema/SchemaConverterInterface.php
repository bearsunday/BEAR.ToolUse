<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\ResourceObject;

/**
 * Converts resource classes to Tool definitions
 */
interface SchemaConverterInterface
{
    /**
     * Convert a resource class to Tool definitions
     *
     * @param class-string<ResourceObject> $resourceClass
     *
     * @return list<Tool>
     */
    public function convert(string $resourceClass, string $resourcePath): array;
}
