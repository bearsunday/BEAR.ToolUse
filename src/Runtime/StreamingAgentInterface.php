<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use Generator;
use InvalidArgumentException;

/**
 * Streaming agent runtime
 */
interface StreamingAgentInterface
{
    /**
     * Run the agent with streaming output
     *
     * @return Generator<int, AgentEvent, mixed, void>
     *
     * @throws InvalidArgumentException When options reference unknown tools.
     */
    public function runStream(string $userMessage, AgentOptions|null $options = null): Generator;

    /**
     * Clear conversation history
     */
    public function reset(): void;
}
