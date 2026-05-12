<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

/**
 * Processes an LLM request before each LLM call
 */
interface InputProcessorInterface
{
    public function process(LlmRequest $request): LlmRequest;
}
