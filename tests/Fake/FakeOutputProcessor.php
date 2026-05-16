<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Runtime\LlmRequest;
use BEAR\ToolUse\Runtime\OutputProcessorInterface;
use Override;

/**
 * Fake output processor for testing
 */
final class FakeOutputProcessor implements OutputProcessorInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $replacementText,
    ) {
    }

    #[Override]
    public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent
    {
        $this->calls++;

        if ($output instanceof LlmResponse) {
            return new LlmResponse(
                $output->stopReason,
                [['type' => 'text', 'text' => $this->replacementText]],
                $output->toolCalls,
            );
        }

        if ($output->type !== StreamEvent::TEXT_DELTA) {
            return $output;
        }

        return new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => $this->replacementText]);
    }
}
