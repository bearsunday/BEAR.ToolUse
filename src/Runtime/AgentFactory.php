<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Llm\LlmClientInterface;
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
    ) {
    }

    /**
     * Add a resource as a tool
     *
     * @param class-string<ResourceObject> $resourceClass
     *
     * @return $this
     */
    public function addResource(string $resourceClass, string $resourcePath): self
    {
        $tools = $this->collector->collect($resourceClass, $resourcePath);
        foreach ($tools as $tool) {
            $this->tools[] = $tool;
        }

        return $this;
    }

    /**
     * Add multiple resources as tools
     *
     * @param array<class-string<ResourceObject>, string> $resources Map of class => path
     *
     * @return $this
     */
    public function addResources(array $resources): self
    {
        $tools = $this->collector->collectAll($resources);
        foreach ($tools as $tool) {
            $this->tools[] = $tool;
        }

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
