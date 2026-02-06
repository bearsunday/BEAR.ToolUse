<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\ResourceObject;

/**
 * Collects tools from resource classes
 */
interface ToolCollectorInterface
{
    /**
     * Collect tools from a resource class
     *
     * @param class-string<ResourceObject> $resourceClass
     *
     * @return list<Tool>
     */
    public function collect(string $resourceClass, string $resourcePath): array;

    /**
     * Collect tools from multiple resource classes
     *
     * @param array<class-string<ResourceObject>, string> $resources Map of class => path
     *
     * @return list<Tool>
     */
    public function collectAll(array $resources): array;
}
