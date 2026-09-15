<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\DuplicateToolMappingException;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Schema\Tool;
use BEAR\ToolUse\Schema\ToolCollectorInterface;

use function sprintf;

/**
 * Factory for creating Agent instances
 */
final class AgentFactory
{
    /** @var list<Tool> */
    private array $tools = [];

    /** @var array<string, true> */
    private array $clientToolNames = [];

    public function __construct(
        private readonly LlmClientInterface $client,
        private DispatcherInterface $dispatcher,
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
     *
     * @throws DuplicateToolMappingException When two URIs map the same tool name to different resources.
     * @throws DuplicateToolNameException When a collected tool name is already registered.
     */
    public function addResources(array $uris): self
    {
        $tools = $this->collector->collect($uris);

        return $this->addTools($tools);
    }

    /**
     * Add already-built tools.
     *
     * Tool names must be unique. Duplicates would send the LLM two definitions
     * of the same name (the same resource added twice, two subagent pools
     * sharing an agent name, or two resource URIs with the same path such as
     * `app://self/article` and `page://self/article`) while `ToolRegistry` maps
     * that name to whichever was registered last. Rename one of them with
     * `#[Tool(name: ...)]` or a distinct `AgentProfile` name.
     *
     * @param list<Tool> $tools
     *
     * @return $this
     *
     * @throws DuplicateToolNameException When a tool name is already registered.
     */
    public function addTools(array $tools): self
    {
        // Validated as a batch before anything is added: a partly applied batch
        // would leave the factory exposing tools its caller believes it rejected
        $registeredNames = $this->registeredToolNames();
        foreach ($tools as $tool) {
            // A server tool named like a client tool would be classified as
            // client by ToolList and bypass the dispatcher entirely
            if (isset($this->clientToolNames[$tool->name])) {
                throw new DuplicateToolNameException(
                    sprintf('Tool "%s" is already registered as a client tool', $tool->name),
                );
            }

            if (isset($registeredNames[$tool->name])) {
                throw new DuplicateToolNameException(sprintf(
                    'Tool "%s" is already registered. Give one of them a distinct name with #[Tool(name: ...)]',
                    $tool->name,
                ));
            }

            $registeredNames[$tool->name] = true;
        }

        $this->tools = [...$this->tools, ...$tools];

        return $this;
    }

    /**
     * Add named subagents as tools.
     *
     * @return $this
     *
     * @throws DuplicateToolNameException When a subagent tool name is already registered as a client tool.
     */
    public function addSubagents(AgentPool $pool): self
    {
        $this->addTools($pool->getTools());
        $this->dispatcher = new AgentDelegator($pool, $this->dispatcher);

        return $this;
    }

    /**
     * Add client-executed tools
     *
     * Client tools are exposed to the LLM but never dispatched server-side.
     * When the LLM calls one, the agent run ends and the call is handed to
     * the consumer for execution; resume the run with the execution results.
     *
     * Client tools must not set `confirm: true`: confirmation is the client's
     * responsibility (the user is already in the loop on the client side).
     * Tool names must be unique across all registered tools; a client tool
     * sharing a name with a server tool would misroute the server tool past
     * the dispatcher.
     *
     * @param list<Tool> $tools
     *
     * @return $this
     *
     * @throws ConfirmableClientToolException When a client tool sets confirm: true.
     * @throws DuplicateToolNameException When a tool name is already registered.
     */
    public function addClientTools(array $tools): self
    {
        // Validated as a batch before anything is added, like addTools()
        $registeredNames = $this->registeredToolNames();
        $clientTools = [];
        foreach ($tools as $tool) {
            if ($tool->confirm) {
                throw new ConfirmableClientToolException(sprintf(
                    'Client tool "%s" must not set confirm: true; confirmation is a client-side concern',
                    $tool->name,
                ));
            }

            if (isset($registeredNames[$tool->name])) {
                throw new DuplicateToolNameException(sprintf('Tool "%s" is already registered', $tool->name));
            }

            $registeredNames[$tool->name] = true;
            $clientTools[] = $tool->client ? $tool : new Tool(
                name: $tool->name,
                description: $tool->description,
                inputSchema: $tool->inputSchema,
                confirm: false,
                filter: $tool->filter,
                client: true,
            );
        }

        foreach ($clientTools as $clientTool) {
            $this->clientToolNames[$clientTool->name] = true;
        }

        $this->tools = [...$this->tools, ...$clientTools];

        return $this;
    }

    /**
     * Create the agent
     */
    public function create(string $systemPrompt, int $maxIterations = 10): OptionAwareAgentInterface
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
    public function createStreaming(string $systemPrompt, int $maxIterations = 10): OptionAwareStreamingAgentInterface
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

    /** @return array<string, true> */
    private function registeredToolNames(): array
    {
        $registeredNames = [];
        foreach ($this->tools as $registeredTool) {
            $registeredNames[$registeredTool->name] = true;
        }

        return $registeredNames;
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
