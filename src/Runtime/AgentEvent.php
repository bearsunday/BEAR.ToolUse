<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use JsonSerializable;
use Override;

/**
 * High-level agent event for application consumption
 */
final readonly class AgentEvent implements JsonSerializable
{
    public const TEXT_DELTA = 'text_delta';
    public const TOOL_START = 'tool_start';
    public const TOOL_RESULT = 'tool_result';
    public const COMPLETED = 'completed';
    public const ERROR = 'error';

    /** @param array<string, mixed> $data */
    private function __construct(
        public string $type,
        public array $data = [],
    ) {
    }

    public static function textDelta(string $text): self
    {
        return new self(self::TEXT_DELTA, ['text' => $text]);
    }

    public static function toolStart(string $toolName): self
    {
        return new self(self::TOOL_START, ['toolName' => $toolName]);
    }

    public static function toolResult(string $toolName): self
    {
        return new self(self::TOOL_RESULT, ['toolName' => $toolName]);
    }

    public static function completed(string $fullText): self
    {
        return new self(self::COMPLETED, ['fullText' => $fullText]);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, ['message' => $message]);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, ...$this->data];
    }
}
