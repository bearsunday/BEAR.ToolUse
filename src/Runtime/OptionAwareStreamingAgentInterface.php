<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use Generator;
use InvalidArgumentException;
use Override;

/**
 * Streaming agent runtime that supports per-run options.
 */
interface OptionAwareStreamingAgentInterface extends StreamingAgentInterface
{
    /**
     * Run the agent with streaming output and optional per-run controls.
     *
     * @return Generator<int, AgentEvent, mixed, void>
     *
     * @throws InvalidArgumentException When options reference unknown tools.
     */
    #[Override]
    public function runStream(string $userMessage, AgentOptions|null $options = null): Generator;
}
