<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\Resource\ResourceObject;
use Throwable;

/**
 * Builds the error text the LLM receives when a tool fails
 *
 * The LLM is a client like any other: what it reads here can end up in a
 * user-facing answer. Implementations decide how much of a failure to
 * disclose. The Throwable itself stays on ToolResult::$throwable for observers.
 */
interface ToolErrorFormatterInterface
{
    /** The resource answered with a status code of 400 or above */
    public function fromStatus(ResourceObject $ro): string;

    /** Dispatch threw: in the resource, the result filter, or JSON encoding */
    public function fromThrowable(Throwable $e): string;
}
