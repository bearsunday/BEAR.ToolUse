<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\ToolCall;

/**
 * Handles user confirmation before executing destructive tool calls
 *
 * Implement this interface to control how confirmation is presented to the user.
 * The LLM's text response serves as the confirmation message.
 *
 * @psalm-type ContentBlock = array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}
 */
interface ConfirmationHandlerInterface
{
    /**
     * Ask user for confirmation before executing a tool call
     *
     * @param ToolCall $toolCall The tool call awaiting confirmation
     * @param string   $llmText  Text from LLM response explaining the action
     *
     * @return bool True to proceed with execution, false to cancel
     */
    public function confirm(ToolCall $toolCall, string $llmText): bool;
}
