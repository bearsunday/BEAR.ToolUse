<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Schema\Tool;
use InvalidArgumentException;
use UnexpectedValueException;

use function array_fill_keys;
use function implode;
use function sprintf;

/**
 * Per-run agent options
 */
final readonly class AgentOptions
{
    /**
     * @param list<string>|null              $enabledTools
     * @param list<InputProcessorInterface>  $inputProcessors
     * @param list<OutputProcessorInterface> $outputProcessors
     */
    public function __construct(
        public array|null $enabledTools = null,
        public array $inputProcessors = [],
        public array $outputProcessors = [],
    ) {
    }

    /** @param list<string> $toolNames */
    public static function withTools(array $toolNames): self
    {
        return new self(enabledTools: $toolNames);
    }

    /**
     * @param list<InputProcessorInterface>  $inputProcessors
     * @param list<OutputProcessorInterface> $outputProcessors
     * @param list<string>|null              $enabledTools
     */
    public static function withProcessors(
        array $inputProcessors = [],
        array $outputProcessors = [],
        array|null $enabledTools = null,
    ): self {
        return new self($enabledTools, $inputProcessors, $outputProcessors);
    }

    public function filtersTools(): bool
    {
        return $this->enabledTools !== null;
    }

    public function enforcesToolList(): bool
    {
        return $this->enabledTools !== null || $this->inputProcessors !== [];
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<Tool>
     *
     * @throws InvalidArgumentException When an enabled tool name is unknown.
     */
    public function filterTools(array $tools): array
    {
        if ($this->enabledTools === null) {
            return $tools;
        }

        $knownTools = [];
        foreach ($tools as $tool) {
            $knownTools[$tool->name] = true;
        }

        $unknownTools = [];
        foreach ($this->enabledTools as $toolName) {
            if (isset($knownTools[$toolName])) {
                continue;
            }

            $unknownTools[] = $toolName;
        }

        if ($unknownTools !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown tool(s): %s',
                implode(', ', $unknownTools),
            ));
        }

        $enabledTools = array_fill_keys($this->enabledTools, true);
        $filteredTools = [];
        foreach ($tools as $tool) {
            if (! isset($enabledTools[$tool->name])) {
                continue;
            }

            $filteredTools[] = $tool;
        }

        return $filteredTools;
    }

    public function processRequest(LlmRequest $request): LlmRequest
    {
        foreach ($this->inputProcessors as $processor) {
            $request = $processor->process($request);
        }

        return $request;
    }

    public function processResponse(LlmResponse $response, LlmRequest $request): LlmResponse
    {
        $output = $response;
        foreach ($this->outputProcessors as $processor) {
            $output = $processor->process($output, $request);
            if (! $output instanceof LlmResponse) {
                throw new UnexpectedValueException('Output processor must return LlmResponse for non-streaming calls.');
            }
        }

        return $output;
    }

    public function processStreamEvent(StreamEvent $event, LlmRequest $request): StreamEvent
    {
        $output = $event;
        foreach ($this->outputProcessors as $processor) {
            $output = $processor->process($output, $request);
            if (! $output instanceof StreamEvent) {
                throw new UnexpectedValueException('Output processor must return StreamEvent for streaming calls.');
            }
        }

        return $output;
    }
}
