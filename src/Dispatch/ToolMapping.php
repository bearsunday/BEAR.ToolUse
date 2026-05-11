<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

/**
 * Resolved mapping from tool name to its backing resource invocation
 */
final readonly class ToolMapping
{
    /** @param class-string<ToolResultFilterInterface>|null $filter */
    public function __construct(
        public string $resourceUri,
        public string $method,
        public string|null $filter = null,
    ) {
    }
}
