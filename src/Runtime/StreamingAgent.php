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
    public function runStream(string $userMessage, AgentOptions|null $options = null): Generator
    {
        $runTools = $this->resolveTools($options);
        $enforceToolList = $options?->enforcesToolList() ?? false;

        $this->messages[] = Message::user($userMessage);
        $fullText = '';
        $hadPreviousText = false;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $request = $this->createRequest($runTools, $options);
            $stream = $this->client->chatStream(
                system: $request->systemPrompt,
                messages: $request->messages,
                tools: $request->tools,
            );
            $stream = $this->processStream($stream, $request, $options);
            $requestToolList = new ToolList($request->tools);

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

                $dispatchGen = $this->dispatchPendingToolCalls(
                    $state->pendingToolCalls,
                    $state->currentText,
                    $requestToolList,
                    $enforceToolList,
                );
                while ($dispatchGen->valid()) {
                    /** @var AgentEvent $currentEvent */
                    $currentEvent = $dispatchGen->current();
                    /** @psalm-suppress MixedAssignment */
                    $sent = yield $currentEvent;
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
            $this->recordContentBlocks($state);

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

    /** @return list<Tool> */
    private function resolveTools(AgentOptions|null $options): array
    {
        return $options?->filterTools($this->tools) ?? $this->tools;
    }

    /** @param list<Tool> $tools */
    private function createRequest(array $tools, AgentOptions|null $options): LlmRequest
    {
        $request = new LlmRequest($this->systemPrompt, $this->messages, $tools);

        return $options?->processRequest($request) ?? $request;
    }

    /**
     * @param Generator<int, StreamEvent, mixed, void> $stream
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    private function processStream(Generator $stream, LlmRequest $request, AgentOptions|null $options): Generator
    {
        foreach ($stream as $event) {
            yield $options?->processStreamEvent($event, $request) ?? $event;
        }
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
    private function dispatchPendingToolCalls(
        array $pendingToolCalls,
        string $currentText,
        ToolList $toolList,
        bool $enforceToolList,
    ): Generator {
        $toolResults = [];
        foreach ($pendingToolCalls as $pending) {
            /** @var array<string, mixed> $input */
            $input = (array) json_decode($pending->inputJson, true);
            $toolCall = new ToolCall(
                id: $pending->id,
                name: $pending->name,
                input: $input,
            );

            if ($enforceToolList && ! $toolList->has($toolCall->name)) {
                $toolResults[] = ToolResult::error($toolCall->id, 'Tool is not enabled: ' . $toolCall->name);

                yield AgentEvent::toolResult($pending->name);

                continue;
            }

            if ($toolList->isConfirmable($toolCall->name)) {
                /** @var bool $approved */
                $approved = yield AgentEvent::confirmationRequired(
                    $toolCall->name,
                    $toolCall->id,
                    $toolCall->input,
                    $currentText,
                );

                if ($approved !== true) {
                    $result = ToolResult::cancelled($toolCall->id);
                    $toolResults[] = $result;

                    yield AgentEvent::toolResult($pending->name);

                    continue;
                }
            }

            try {
                $result = $this->dispatcher->dispatch($toolCall);
            } catch (Throwable $e) {
                $result = ToolResult::error($toolCall->id, $e::class . ': ' . $e->getMessage());
            }

            $toolResults[] = $result;

            yield AgentEvent::toolResult($pending->name);
        }

        return $toolResults;
    }
}
