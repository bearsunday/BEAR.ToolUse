<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Llm\StreamEvent;

/**
 * Processes an LLM response or stream event after each LLM call.
 *
 * Implementations must return the same concrete type they receive: `LlmResponse`
 * for normal agent calls and `StreamEvent` for streaming calls. Processors may
 * rewrite text content, but must preserve tool-use control data (`tool_use`
 * content blocks and stream tool-use ids/names/input) so the agent can dispatch
 * tools safely.
 */
interface OutputProcessorInterface
{
    public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent;
}
