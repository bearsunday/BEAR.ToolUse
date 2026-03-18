<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use Override;
use Throwable;

use function is_string;
use function json_decode;

/**
 * Streaming agent runtime
 *
 * Yields AgentEvent instances as the LLM generates output.
 * Text deltas are yielded immediately for real-time display.
 * Tool calls are executed and results fed back to the LLM.
 *
 * @psalm-type PendingToolCall = array{id: string, name: string, inputJson: string}
 * @psalm-type ContentBlock = array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}
 */
final class StreamingAgent implements StreamingAgentInterface
{
    /** @var list<Message> */
    public array $messages = [];

    /** @param list<Tool> $tools */
    public function __construct(
        private readonly StreamingLlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly array $tools,
        private readonly string $systemPrompt,
        private readonly int $maxIterations = 10,
    ) {
    }

    /** @return Generator<int, AgentEvent, mixed, void> */
    #[Override]
    public function runStream(string $userMessage): Generator
    {
        $this->messages[] = Message::user($userMessage);
        $fullText = '';
        $hadPreviousText = false;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $stream = $this->client->chatStream(
                system: $this->systemPrompt,
                messages: $this->messages,
                tools: $this->tools,
            );

            $consumeGen = $this->consumeStream($stream, $hadPreviousText, $fullText);
            foreach ($consumeGen as $event) {
                yield $event;
            }

            /** @var array{stopReason: string, currentText: string, pendingToolCalls: list<PendingToolCall>, contentBlocks: list<ContentBlock>, fullText: string} $state */
            $state = $consumeGen->getReturn();
            $fullText = $state['fullText'];

            if ($state['stopReason'] === 'end_turn') {
                if ($state['contentBlocks'] !== []) {
                    $this->messages[] = Message::assistant($state['contentBlocks']);
                }

                yield AgentEvent::completed($fullText);

                return;
            }

            if ($state['stopReason'] === 'tool_use' && $state['pendingToolCalls'] !== []) {
                $this->messages[] = Message::assistant($state['contentBlocks']);
                $dispatchGen = $this->dispatchPendingToolCalls($state['pendingToolCalls']);
                foreach ($dispatchGen as $event) {
                    yield $event;
                }

                /** @var list<ToolResult> $toolResults */
                $toolResults = $dispatchGen->getReturn();
                $this->messages[] = Message::toolResults($toolResults);
                if ($state['currentText'] !== '') {
                    $hadPreviousText = true;
                }

                continue;
            }

            // Other stop reasons (max_tokens, stop_sequence) - complete
            yield AgentEvent::completed($fullText);

            return;
        }

        yield AgentEvent::error('Max iterations reached');
    }

    #[Override]
    public function reset(): void
    {
        $this->messages = [];
    }

    /**
     * Consume stream events and yield AgentEvents
     *
     * @param Generator<int, StreamEvent, mixed, void> $stream
     *
     * @return Generator<int, AgentEvent, mixed, array{stopReason: string, currentText: string, pendingToolCalls: list<PendingToolCall>, contentBlocks: list<ContentBlock>, fullText: string}>
     */
    private function consumeStream(Generator $stream, bool $hadPreviousText, string $fullText): Generator
    {
        $currentText = '';
        $needsSeparator = $hadPreviousText;
        $stopReason = 'end_turn';
        /** @var list<PendingToolCall> $pendingToolCalls */
        $pendingToolCalls = [];
        $currentToolId = '';
        $currentToolName = '';
        $currentToolInputJson = '';
        /** @var list<ContentBlock> $contentBlocks */
        $contentBlocks = [];

        foreach ($stream as $event) {
            switch ($event->type) {
                case StreamEvent::TEXT_DELTA:
                    $text = $this->eventString($event, 'text');
                    if ($needsSeparator) {
                        $fullText .= "\n";

                        yield AgentEvent::textDelta("\n");

                        $needsSeparator = false;
                    }

                    $currentText .= $text;
                    $fullText .= $text;

                    yield AgentEvent::textDelta($text);

                    break;

                case StreamEvent::TOOL_USE_START:
                    $currentToolId = $this->eventString($event, 'id');
                    $currentToolName = $this->eventString($event, 'name');
                    $currentToolInputJson = '';

                    yield AgentEvent::toolStart($currentToolName);

                    break;

                case StreamEvent::TOOL_USE_DELTA:
                    $currentToolInputJson .= $this->eventString($event, 'input');

                    break;

                case StreamEvent::CONTENT_BLOCK_STOP:
                    $this->finalizeContentBlock(
                        $currentToolName,
                        $currentToolId,
                        $currentToolInputJson,
                        $currentText,
                        $pendingToolCalls,
                        $contentBlocks,
                    );
                    $currentToolId = '';
                    $currentToolName = '';
                    $currentToolInputJson = '';

                    break;

                case StreamEvent::MESSAGE_STOP:
                    $stopReason = $this->eventString($event, 'stopReason', 'end_turn');

                    break;
            }
        }

        return [
            'stopReason' => $stopReason,
            'currentText' => $currentText,
            'pendingToolCalls' => $pendingToolCalls,
            'contentBlocks' => $contentBlocks,
            'fullText' => $fullText,
        ];
    }

    /**
     * Dispatch pending tool calls and yield result events
     *
     * @param list<PendingToolCall> $pendingToolCalls
     *
     * @return Generator<int, AgentEvent, mixed, list<ToolResult>>
     */
    private function dispatchPendingToolCalls(array $pendingToolCalls): Generator
    {
        $toolResults = [];
        foreach ($pendingToolCalls as $pending) {
            /** @var array<string, mixed> $input */
            $input = (array) json_decode($pending['inputJson'], true);
            $toolCall = new ToolCall(
                id: $pending['id'],
                name: $pending['name'],
                input: $input,
            );

            try {
                $result = $this->dispatcher->dispatch($toolCall);
            } catch (Throwable $e) {
                $result = ToolResult::error($toolCall->id, $e::class . ': ' . $e->getMessage());
            }

            $toolResults[] = $result;

            yield AgentEvent::toolResult($pending['name']);
        }

        return $toolResults;
    }

    /**
     * Finalize a content block when CONTENT_BLOCK_STOP is received
     *
     * @param list<PendingToolCall> $pendingToolCalls
     * @param list<ContentBlock>    $contentBlocks
     */
    private function finalizeContentBlock(
        string $toolName,
        string $toolId,
        string $toolInputJson,
        string $currentText,
        array &$pendingToolCalls,
        array &$contentBlocks,
    ): void {
        if ($toolName !== '') {
            $pendingToolCalls[] = [
                'id' => $toolId,
                'name' => $toolName,
                'inputJson' => $toolInputJson,
            ];
            /** @var array<string, mixed> $input */
            $input = (array) json_decode($toolInputJson, true);
            $contentBlocks[] = [
                'type' => 'tool_use',
                'id' => $toolId,
                'name' => $toolName,
                'input' => $input,
            ];
        } elseif ($currentText !== '') {
            $contentBlocks[] = ['type' => 'text', 'text' => $currentText];
        }
    }

    /** Extract a string value from stream event data */
    private function eventString(StreamEvent $event, string $key, string $default = ''): string
    {
        $value = $event->data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
