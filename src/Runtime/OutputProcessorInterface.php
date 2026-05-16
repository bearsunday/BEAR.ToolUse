<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Llm\StreamEvent;

/**
 * Processes an LLM response after each LLM call
 */
interface OutputProcessorInterface
{
    public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent;
}
