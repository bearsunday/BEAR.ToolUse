<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Schema\Tool;
use BEAR\ToolUse\Types;
use InvalidArgumentException;
use UnexpectedValueException;

use function array_fill_keys;
use function count;
use function implode;
use function sprintf;

/**
 * Per-run agent options
 *
 * @psalm-import-type ContentBlock from Types
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
        return new self(
            enabledTools: $enabledTools,
            inputProcessors: $inputProcessors,
            outputProcessors: $outputProcessors,
        );
    }

    public function filtersTools(): bool
    {
        return $this->enabledTools !== null;
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

        $this->assertToolUseContentIsPreserved($response, $output);

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

        $this->assertStreamEventIsPreserved($event, $output);

        return $output;
    }

    private function assertToolUseContentIsPreserved(LlmResponse $input, LlmResponse $output): void
    {
        if ($input->stopReason !== 'tool_use' || $input->toolCalls === []) {
            return;
        }

        if ($output->stopReason !== $input->stopReason) {
            throw new UnexpectedValueException('Output processor must preserve tool_use stop reason.');
        }

        $this->assertToolCallsArePreserved($input, $output);

        $toolUseBlocks = $this->toolUseBlocksById($output);

        foreach ($input->toolCalls as $toolCall) {
            if ($this->toolUseBlockMatchesToolCall($toolUseBlocks[$toolCall->id] ?? null, $toolCall)) {
                continue;
            }

            throw new UnexpectedValueException('Output processor must preserve tool_use content blocks for tool calls.');
        }
    }

    private function assertToolCallsArePreserved(LlmResponse $input, LlmResponse $output): void
    {
        if (count($output->toolCalls) !== count($input->toolCalls)) {
            throw new UnexpectedValueException('Output processor must preserve tool_use tool calls.');
        }

        foreach ($input->toolCalls as $index => $toolCall) {
            $outputToolCall = $output->toolCalls[$index] ?? null;
            if (
                $outputToolCall instanceof ToolCall
                && $outputToolCall->id === $toolCall->id
                && $outputToolCall->name === $toolCall->name
                && $outputToolCall->input === $toolCall->input
            ) {
                continue;
            }

            throw new UnexpectedValueException('Output processor must preserve tool_use tool calls.');
        }
    }

    /** @return array<string, ContentBlock> */
    private function toolUseBlocksById(LlmResponse $response): array
    {
        $toolUseBlocks = [];
        foreach ($response->content as $block) {
            if ($block['type'] !== 'tool_use' || ! isset($block['id'])) {
                continue;
            }

            $toolUseBlocks[$block['id']] = $block;
        }

        return $toolUseBlocks;
    }

    /** @param array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}|null $block */
    private function toolUseBlockMatchesToolCall(array|null $block, ToolCall $toolCall): bool
    {
        return $block !== null
            && ($block['name'] ?? null) === $toolCall->name
            && ($block['input'] ?? null) === $toolCall->input;
    }

    private function assertStreamEventIsPreserved(StreamEvent $input, StreamEvent $output): void
    {
        if ($input->type !== $output->type) {
            throw new UnexpectedValueException('Output processor must preserve stream event type.');
        }

        foreach ($this->streamEventPreservedKeys($input->type) as $key) {
            if (($input->data[$key] ?? null) === ($output->data[$key] ?? null)) {
                continue;
            }

            throw new UnexpectedValueException('Output processor must preserve stream tool-use control data.');
        }
    }

    /** @return list<string> */
    private function streamEventPreservedKeys(string $type): array
    {
        return match ($type) {
            StreamEvent::TOOL_USE_START => ['id', 'name'],
            StreamEvent::TOOL_USE_DELTA => ['input'],
            StreamEvent::MESSAGE_STOP => ['stopReason'],
            default => [],
        };
    }
}
