<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Runtime\ConfirmationHandlerInterface;
use Override;

/**
 * Fake confirmation handler for testing
 */
final class FakeConfirmationHandler implements ConfirmationHandlerInterface
{
    private bool $willConfirm = true;

    /** @var list<array{toolCall: ToolCall, llmText: string}> */
    public array $calls = [];

    public function setWillConfirm(bool $willConfirm): void
    {
        $this->willConfirm = $willConfirm;
    }

    #[Override]
    public function confirm(ToolCall $toolCall, string $llmText): bool
    {
        $this->calls[] = [
            'toolCall' => $toolCall,
            'llmText' => $llmText,
        ];

        return $this->willConfirm;
    }
}
