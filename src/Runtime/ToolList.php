<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\Tool;

use function array_key_exists;

/**
 * Tool collection with query methods
 */
final readonly class ToolList
{
    /** @var array<string, bool> */
    private array $confirmable;

    /** @param list<Tool> $tools */
    public function __construct(array $tools)
    {
        $confirmable = [];
        foreach ($tools as $tool) {
            if (! $tool->confirm) {
                continue;
            }

            $confirmable[$tool->name] = true;
        }

        $this->confirmable = $confirmable;
    }

    public function isConfirmable(string $name): bool
    {
        return array_key_exists($name, $this->confirmable);
    }
}
