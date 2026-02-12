<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

/**
 * Filters tool result body before sending to LLM
 */
interface ToolResultFilterInterface
{
    public function __invoke(mixed $body): mixed;
}
