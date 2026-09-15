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
 * rewrite text content, but the control data the agent branches on must survive
 * untouched: the stop reason and the tool calls of every response, the
 * `tool_use` content blocks of a `tool_use` response, and the stream tool-use
 * ids/names/input. A processor can neither drop a tool call nor introduce one.
 * `OutputProcessorGuard` enforces this after the processor chain runs.
 */
interface OutputProcessorInterface
{
    public function process(LlmResponse|StreamEvent $output, LlmRequest $request): LlmResponse|StreamEvent;
}
