<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

/**
 * Result of a tool execution
 */
final readonly class ToolResult
{
    private function __construct(
        public string $toolUseId,
        public bool $isError,
        public mixed $content,
    ) {
    }

    public static function success(string $toolUseId, mixed $content): self
    {
        return new self($toolUseId, false, $content);
    }

    public static function error(string $toolUseId, string $message): self
    {
        return new self($toolUseId, true, $message);
    }

    public static function cancelled(string $toolUseId): self
    {
        return new self($toolUseId, true, 'User cancelled this operation.');
    }
}
