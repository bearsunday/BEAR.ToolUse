<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

/**
 * Represents a tool call from LLM
 */
final readonly class ToolCall
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {
    }

    /**
     * Create from array data
     *
     * @param array{id: string, name: string, input?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            input: $data['input'] ?? [],
        );
    }
}
