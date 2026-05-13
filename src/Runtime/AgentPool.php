<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Schema\Tool;
use BEAR\ToolUse\Schema\ToolCollectorInterface;
use InvalidArgumentException;

use function sprintf;

/**
 * Registry and factory for named subagents
 */
final class AgentPool
{
    /** @var array<string, AgentProfile> */
    private array $profiles = [];

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly ToolCollectorInterface $collector,
        private readonly ConfirmationHandlerInterface|null $confirmationHandler = null,
    ) {
    }

    public function register(AgentProfile $profile): self
    {
        $this->profiles[$profile->name] = $profile;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    public function get(string $name): AgentProfile
    {
        if (! isset($this->profiles[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown agent: %s',
                $name,
            ));
        }

        return $this->profiles[$name];
    }

    public function create(string $name): OptionAwareAgentInterface
    {
        $profile = $this->get($name);
        $tools = $this->collector->collect($profile->resources);

        $agent = new Agent(
            client: $this->client,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: $profile->systemPrompt,
            maxIterations: $profile->maxIterations,
            confirmationHandler: $this->confirmationHandler ?? new DenyConfirmationHandler(),
        );

        return new ProfiledAgent($agent, $profile->options);
    }

    /** @return list<Tool> */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->profiles as $profile) {
            $tools[] = $profile->toTool();
        }

        return $tools;
    }
}
