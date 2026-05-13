<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use InvalidArgumentException;
use Override;

/**
 * Agent runtime that supports per-run options.
 */
interface OptionAwareAgentInterface extends AgentInterface
{
    /**
     * Run the agent with a user message and optional per-run controls.
     *
     * @throws InvalidArgumentException When options reference unknown tools.
     */
    #[Override]
    public function run(string $userMessage, AgentOptions|null $options = null): AgentResponse;
}
