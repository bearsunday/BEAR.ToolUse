<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use Override;

/**
 * Agent wrapper that applies profile default options.
 */
final readonly class ProfiledAgent implements OptionAwareAgentInterface
{
    public function __construct(
        private Agent $agent,
        private AgentOptions|null $defaultOptions,
    ) {
    }

    #[Override]
    public function run(string $userMessage, AgentOptions|null $options = null): AgentResponse
    {
        return $this->agent->run($userMessage, $options ?? $this->defaultOptions);
    }

    #[Override]
    public function reset(): void
    {
        $this->agent->reset();
    }
}
