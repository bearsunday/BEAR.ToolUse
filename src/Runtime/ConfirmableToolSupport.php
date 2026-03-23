<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Schema\Tool;

use function array_key_exists;
use function assert;

/**
 * Shared confirmation logic for Agent and StreamingAgent
 */
trait ConfirmableToolSupport
{
    private const string CANCELLED_MESSAGE = 'User cancelled this operation.';

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

    private function isCancelled(ToolCall $toolCall, string $llmText): bool
    {
        if (! $this->requiresConfirmation($toolCall)) {
            return false;
        }

        $confirmationHandler = $this->confirmationHandler;
        assert($confirmationHandler instanceof ConfirmationHandlerInterface);

        return ! $confirmationHandler->confirm($toolCall, $llmText);
    }

    private function requiresConfirmation(ToolCall $toolCall): bool
    {
        return $this->confirmationHandler !== null
            && array_key_exists($toolCall->name, $this->confirmableTools);
    }
}
