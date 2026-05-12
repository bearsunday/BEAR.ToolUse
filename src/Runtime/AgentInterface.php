<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use InvalidArgumentException;

/**
 * Agent runtime for managing LLM conversation loop
 */
interface AgentInterface
{
    /**
     * Run the agent with a user message
     *
     * @throws InvalidArgumentException When options reference unknown tools.
     */
    public function run(string $userMessage, AgentOptions|null $options = null): AgentResponse;

    /**
     * Clear conversation history to start a new conversation
     */
    public function reset(): void;
}
