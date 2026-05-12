<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\Tool;
use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * Configuration for a named subagent
 */
final readonly class AgentProfile
{
    /** @param list<string> $resources */
    public function __construct(
        public string $name,
        public string $description,
        public string $systemPrompt,
        public array $resources = [],
        public int $maxIterations = 10,
        public AgentOptions|null $options = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid agent name: %s',
                $name,
            ));
        }
    }

    public function toolName(): string
    {
        return 'ask_' . $this->name;
    }

    public function toTool(): Tool
    {
        return new Tool(
            name: $this->toolName(),
            description: $this->description,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Question or task for the subagent.',
                    ],
                    'context' => [
                        'type' => 'object',
                        'description' => 'Additional context for the subagent.',
                    ],
                ],
                'required' => ['message'],
            ],
        );
    }
}
