<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use Throwable;

/**
 * Result of a tool execution
 *
 * Only $toolUseId, $isError, and $content reach the LLM. $throwable is for
 * observers and logs: the cause of an error whose $content may not name it.
 */
final readonly class ToolResult
{
    private function __construct(
        public string $toolUseId,
        public bool $isError,
        public mixed $content,
        public Throwable|null $throwable = null,
    ) {
    }

    public static function success(string $toolUseId, mixed $content): self
    {
        return new self($toolUseId, false, $content);
    }

    public static function error(string $toolUseId, string $message, Throwable|null $throwable = null): self
    {
        return new self($toolUseId, true, $message, $throwable);
    }

    public static function cancelled(string $toolUseId): self
    {
        return new self($toolUseId, true, 'User cancelled this operation.');
    }
}
