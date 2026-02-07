<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use JsonSerializable;
use Override;

/**
 * Tool definition for AI agent
 *
 * @psalm-type InputSchema = array{type: string, properties: array<string, mixed>, required?: list<string>}
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
final readonly class Tool implements JsonSerializable
{
    /** @param InputSchema $inputSchema */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public bool $confirm = false,
    ) {
    }

    /** @return array{name: string, description: string, input_schema: InputSchema} */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }
}
