<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Runtime\InputProcessorInterface;
use BEAR\ToolUse\Runtime\LlmRequest;
use BEAR\ToolUse\Runtime\Message;
use Override;

/**
 * Fake input processor for testing
 */
final class FakeInputProcessor implements InputProcessorInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $systemSuffix = '',
        private readonly string $memoryText = 'memory',
    ) {
    }

    #[Override]
    public function process(LlmRequest $request): LlmRequest
    {
        $this->calls++;

        return $request
            ->withSystemPrompt($request->systemPrompt . $this->systemSuffix)
            ->withMessages([...$request->messages, Message::user($this->memoryText)]);
    }
}
