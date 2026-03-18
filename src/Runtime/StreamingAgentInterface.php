<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use Generator;

/**
 * Streaming agent runtime
 */
interface StreamingAgentInterface
{
    /**
     * Run the agent with streaming output
     *
     * @return Generator<int, AgentEvent, void>
     */
    public function runStream(string $userMessage): Generator;

    /**
     * Clear conversation history
     */
    public function reset(): void;
}
