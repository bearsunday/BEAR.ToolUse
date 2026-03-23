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

use function array_key_exists;
use function assert;
use function json_decode;

/**
 * Streaming agent runtime
 *
 * Yields AgentEvent instances as the LLM generates output.
 * Text deltas are yielded immediately for real-time display.
 * Tool calls are executed and results fed back to the LLM.
 *
 * For confirmable tools, yields AgentEvent::CONFIRMATION_REQUIRED and
 * receives approval via Generator::send(bool). If send() is not called
 * (e.g. iterator_to_array), the tool is denied by default (safe default).
 *
 * @psalm-import-type PendingToolCall from StreamIterationState
 */
final class StreamingAgent implements StreamingAgentInterface
{
    use ConfirmableToolSupport;

    /** @var list<Message> */
    public array $messages = [];

    /** @var array<string, bool> */
    private readonly array $confirmableTools;

    /** @param list<Tool> $tools */
    public function __construct(
        private readonly StreamingLlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly array $tools,
        private readonly string $systemPrompt,
        private readonly int $maxIterations = 10,
    ) {
        $this->confirmableTools = $this->buildConfirmableTools($this->tools);
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

            $state = $consumeGen->getReturn();
            $fullText = $state->fullText;

            if ($state->stopReason === 'end_turn') {
                $this->recordContentBlocks($state);

                yield AgentEvent::completed($fullText);

                return;
            }

            if ($state->stopReason === 'tool_use' && $state->pendingToolCalls !== []) {
                $this->messages[] = Message::assistant($state->contentBlocks);

                $dispatchGen = $this->dispatchPendingToolCalls($state->pendingToolCalls, $state->currentText);
                while ($dispatchGen->valid()) {
                    $event = $dispatchGen->current();
                    assert($event instanceof AgentEvent);

                    /** @psalm-suppress MixedAssignment */
                    $sent = yield $event;
                    /** @var bool $approved */
                    $approved = $sent;
                    $dispatchGen->send($approved);
                }

                /** @var list<ToolResult> $toolResults */
                $toolResults = $dispatchGen->getReturn();
                $this->messages[] = Message::toolResults($toolResults);
                if ($state->currentText !== '') {
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

    private function recordContentBlocks(StreamIterationState $state): void
    {
        if ($state->contentBlocks === []) {
            return;
        }

        $this->messages[] = Message::assistant($state->contentBlocks);
    }

    /**
     * Consume stream events and yield AgentEvents
     *
     * @param Generator<int, StreamEvent, mixed, void> $stream
     *
     * @return Generator<int, AgentEvent, mixed, StreamIterationState>
     */
    private function consumeStream(Generator $stream, bool $hadPreviousText, string $fullText): Generator
    {
        $accumulator = new StreamContentAccumulator($hadPreviousText, $fullText);

        foreach ($stream as $event) {
            foreach ($accumulator->handleEvent($event) as $agentEvent) {
                yield $agentEvent;
            }
        }

        return $accumulator->toState();
    }

    /**
     * Dispatch pending tool calls and yield result events
     *
     * @param list<PendingToolCall> $pendingToolCalls
     * @param string                $currentText      LLM text for confirmation prompt
     *
     * @return Generator<int, AgentEvent, bool, list<ToolResult>>
     */
    private function dispatchPendingToolCalls(array $pendingToolCalls, string $currentText): Generator
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

            if (array_key_exists($toolCall->name, $this->confirmableTools)) {
                /** @var bool $approved */
                $approved = yield AgentEvent::confirmationRequired(
                    $toolCall->name,
                    $toolCall->id,
                    $toolCall->input,
                    $currentText,
                );

                if ($approved !== true) {
                    $result = ToolResult::error($toolCall->id, self::CANCELLED_MESSAGE);
                    $toolResults[] = $result;

                    yield AgentEvent::toolResult($pending['name']);

                    continue;
                }
            }

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
}
