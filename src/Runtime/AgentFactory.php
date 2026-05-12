<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Schema\Tool;
use BEAR\ToolUse\Schema\ToolCollectorInterface;

/**
 * Factory for creating Agent instances
 */
final class AgentFactory
{
    /** @var list<Tool> */
    private array $tools = [];

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly ToolCollectorInterface $collector,
        private readonly ToolRegistryInterface $registry,
        private readonly ConfirmationHandlerInterface|null $confirmationHandler = null,
        private readonly StreamingLlmClientInterface|null $streamingClient = null,
    ) {
    }

    /**
     * Add resources as tools
     *
     * @param list<string> $uris List of full resource URIs (e.g., ["app://self/user", "app://self/article"])
     *
     * @return $this
     */
    public function addResources(array $uris): self
    {
        $tools = $this->collector->collect($uris);

        return $this->addTools($tools);
    }

    /**
     * Add already-built tools.
     *
     * @param list<Tool> $tools
     *
     * @return $this
     */
    public function addTools(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->tools[] = $tool;
        }

        return $this;
    }

    /**
     * Add named subagents as tools.
     *
     * @return $this
     */
    public function addSubagents(AgentPool $pool): self
    {
        $this->addTools($pool->getTools());

        return $this;
    }

    /**
     * Create the agent
     */
    public function create(string $systemPrompt, int $maxIterations = 10): AgentInterface
    {
        return new Agent(
            client: $this->client,
            dispatcher: $this->dispatcher,
            tools: $this->tools,
            systemPrompt: $systemPrompt,
            maxIterations: $maxIterations,
            confirmationHandler: $this->confirmationHandler,
        );
    }

    /**
     * Create the streaming agent
     */
    public function createStreaming(string $systemPrompt, int $maxIterations = 10): StreamingAgentInterface
    {
        if ($this->streamingClient === null) {
            throw new StreamingNotConfiguredException('StreamingLlmClientInterface is not configured');
        }

        return new StreamingAgent(
            client: $this->streamingClient,
            dispatcher: $this->dispatcher,
            tools: $this->tools,
            systemPrompt: $systemPrompt,
            maxIterations: $maxIterations,
        );
    }

    /**
     * Get collected tools (for inspection)
     *
     * @return list<Tool>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Get the tool registry (for inspection)
     */
    public function getRegistry(): ToolRegistryInterface
    {
        return $this->registry;
    }
}
