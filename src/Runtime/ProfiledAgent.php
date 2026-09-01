<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use Override;

/**
 * Agent wrapper that applies profile default options.
 *
 * Per-call options are merged over the profile's rather than replacing them, so
 * a caller cannot widen the tool set the profile allows.
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
        return $this->agent->run($userMessage, $this->mergeOptions($options));
    }

    private function mergeOptions(AgentOptions|null $options): AgentOptions|null
    {
        if ($options === null || $this->defaultOptions === null) {
            return $options ?? $this->defaultOptions;
        }

        return $options->mergeDefaults($this->defaultOptions);
    }

    #[Override]
    public function reset(): void
    {
        $this->agent->reset();
    }
}
