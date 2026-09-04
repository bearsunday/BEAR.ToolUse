<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\ToolCall;
use Override;

/**
 * Confirmation handler that denies every confirmable tool call.
 */
final readonly class DenyConfirmationHandler implements ConfirmationHandlerInterface
{
    #[Override]
    public function confirm(ToolCall $toolCall, string $llmText): bool
    {
        return false;
    }
}
