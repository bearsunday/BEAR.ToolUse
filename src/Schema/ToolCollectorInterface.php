<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

/**
 * Collects tools from resource URIs
 */
interface ToolCollectorInterface
{
    /**
     * Collect tools from resource URIs
     *
     * @param list<string> $uris List of full resource URIs (e.g., ["app://self/user", "app://self/article"])
     *
     * @return list<Tool>
     */
    public function collect(array $uris): array;
}
