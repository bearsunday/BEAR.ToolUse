<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\Tool;

/**
 * Shared confirmation logic for Agent and StreamingAgent
 */
trait ConfirmableToolSupport
{
    /** @psalm-suppress MissingClassConstType */
    private const CANCELLED_MESSAGE = 'User cancelled this operation.';

    /**
     * Build a lookup map of confirmable tool names
     *
     * @param list<Tool> $tools
     *
     * @return array<string, bool>
     */
    private function buildConfirmableTools(array $tools): array
    {
        $confirmable = [];
        foreach ($tools as $tool) {
            if (! $tool->confirm) {
                continue;
            }

            $confirmable[$tool->name] = true;
        }

        return $confirmable;
    }
}
