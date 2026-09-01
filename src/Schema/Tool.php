<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;
use BEAR\ToolUse\Types;
use JsonSerializable;
use Override;

/**
 * Tool definition for AI agent
 *
 * @psalm-import-type InputSchema from Types
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
final readonly class Tool implements JsonSerializable
{
    /** @param InputSchema $inputSchema */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public bool $confirm = false,
        /** @var class-string<ToolResultFilterInterface>|null */
        public string|null $filter = null,
        public bool $client = false,
    ) {
    }

    /** @return array{name: string, description: string, input_schema: InputSchema, confirm?: true, client?: true} */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];

        if ($this->confirm) {
            $data['confirm'] = true;
        }

        if ($this->client) {
            $data['client'] = true;
        }

        return $data;
    }
}
