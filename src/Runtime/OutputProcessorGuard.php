<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Types;
use UnexpectedValueException;

use function count;

/**
 * Asserts that output processors leave the agent's control flow intact
 *
 * Processors may rewrite text, but the agent branches on what they return, so
 * the tool-use control data must survive them untouched.
 *
 * @psalm-import-type ContentBlock from Types
 */
final readonly class OutputProcessorGuard
{
    /** @throws UnexpectedValueException When the processed response would drive a different control flow. */
    public function assertResponse(LlmResponse $input, LlmResponse $output): void
    {
        $this->assertControlDataIsPreserved($input, $output);
        $this->assertToolUseContentIsPreserved($input, $output);
    }

    /** @throws UnexpectedValueException When the processed event loses stream tool-use control data. */
    public function assertStreamEvent(StreamEvent $input, StreamEvent $output): void
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

    /**
     * Assert that the processed response drives the same control flow as the original
     *
     * Checked for every stop reason, not only `tool_use`: the agent branches on the
     * processed response, so a processor that turns a text answer into a tool call
     * would otherwise dispatch a tool the LLM never asked for, and append a
     * `tool_result` message with no `tool_use` block to pair with.
     */
    private function assertControlDataIsPreserved(LlmResponse $input, LlmResponse $output): void
    {
        if ($output->stopReason !== $input->stopReason) {
            throw new UnexpectedValueException('Output processor must preserve the stop reason.');
        }

        if (count($output->toolCalls) !== count($input->toolCalls)) {
            throw new UnexpectedValueException('Output processor must preserve the tool calls.');
        }

        foreach ($input->toolCalls as $index => $toolCall) {
            $outputToolCall = $output->toolCalls[$index] ?? null;
            if ($this->toolCallMatches($outputToolCall, $toolCall)) {
                continue;
            }

            throw new UnexpectedValueException('Output processor must preserve the tool calls.');
        }
    }

    private function toolCallMatches(ToolCall|null $outputToolCall, ToolCall $toolCall): bool
    {
        return $outputToolCall instanceof ToolCall
            && $outputToolCall->id === $toolCall->id
            && $outputToolCall->name === $toolCall->name
            && $outputToolCall->input === $toolCall->input;
    }

    private function assertToolUseContentIsPreserved(LlmResponse $input, LlmResponse $output): void
    {
        if ($input->stopReason !== 'tool_use' || $input->toolCalls === []) {
            return;
        }

        // Paired by position, so an added, duplicated or dropped block is caught
        // as well as a rewritten one. An unpaired tool_use block would reach the
        // next request as an assistant call with no tool_result answering it.
        $toolUseBlocks = $this->toolUseBlocks($output);
        if (count($toolUseBlocks) !== count($input->toolCalls)) {
            throw new UnexpectedValueException('Output processor must preserve tool_use content blocks for tool calls.');
        }

        foreach ($input->toolCalls as $index => $toolCall) {
            if ($this->toolUseBlockMatchesToolCall($toolUseBlocks[$index] ?? null, $toolCall)) {
                continue;
            }

            throw new UnexpectedValueException('Output processor must preserve tool_use content blocks for tool calls.');
        }
    }

    /** @return list<ContentBlock> */
    private function toolUseBlocks(LlmResponse $response): array
    {
        $toolUseBlocks = [];
        foreach ($response->content as $block) {
            if ($block['type'] !== 'tool_use') {
                continue;
            }

            $toolUseBlocks[] = $block;
        }

        return $toolUseBlocks;
    }

    /** @param array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}|null $block */
    private function toolUseBlockMatchesToolCall(array|null $block, ToolCall $toolCall): bool
    {
        return $block !== null
            && ($block['id'] ?? null) === $toolCall->id
            && ($block['name'] ?? null) === $toolCall->name
            && ($block['input'] ?? null) === $toolCall->input;
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
