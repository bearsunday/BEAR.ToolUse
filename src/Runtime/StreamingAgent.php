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
use JsonException;
use Override;
use Throwable;

use function json_decode;

use const JSON_THROW_ON_ERROR;

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
final class StreamingAgent implements OptionAwareStreamingAgentInterface
{
    /** @var list<Message> */
    public array $messages = [];

    /** @var list<ToolResult> Server-side results held while awaiting client tool execution */
    private array $pendingToolResults = [];

    /** Every registered tool, used to classify client calls on resume */
    private readonly ToolList $toolList;

    /** @param list<Tool> $tools */
    public function __construct(
        private readonly StreamingLlmClientInterface $client,
        private readonly DispatcherInterface $dispatcher,
        private readonly array $tools,
        private readonly string $systemPrompt,
        private readonly int $maxIterations = 10,
    ) {
        $this->toolList = new ToolList($this->tools);
    }

    /** @return Generator<int, AgentEvent, mixed, void> */
    #[Override]
    public function runStream(string $userMessage, AgentOptions|null $options = null): Generator
    {
        $runTools = $this->resolveTools($options);

        $this->messages[] = Message::user($userMessage);

        yield from $this->loop($runTools, $options);
    }

    /**
     * Resume the loop with client tool execution results
     *
     * Call after runStream() ended with CLIENT_TOOL_CALL events. Server-side
     * results from the interrupted turn are merged in automatically.
     * Validation runs eagerly at call time (not at first iteration) so the
     * consumer can reject an invalid resume before starting a response stream.
     *
     * @param list<ToolResult> $toolResults
     *
     * @return Generator<int, AgentEvent, mixed, void>
     *
     * @throws InvalidResumeException When no client tool calls are awaiting results,
     * a server call of the interrupted turn lacks its held result, or the supplied
     * result IDs do not match the awaited client calls exactly once each.
     */
    public function resumeStream(array $toolResults, AgentOptions|null $options = null): Generator
    {
        ResumeValidator::validate($this->messages, $this->toolList, $this->pendingToolResults, $toolResults);

        $runTools = $this->resolveTools($options);

        $this->messages[] = Message::toolResults([...$this->pendingToolResults, ...$toolResults]);
        $this->pendingToolResults = [];

        return $this->loop($runTools, $options);
    }

    /**
     * @param list<Tool> $runTools
     *
     * @return Generator<int, AgentEvent, mixed, void>
     */
    private function loop(array $runTools, AgentOptions|null $options): Generator
    {
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

                // Manual iteration keeps this generator's key sequence intact
                // (a nested `yield from` would restart keys and break iterator_to_array())
                $turnGen = $this->processToolUseTurn($state, $requestToolList);
                while ($turnGen->valid()) {
                    /** @var AgentEvent $currentEvent */
                    $currentEvent = $turnGen->current();
                    /** @psalm-suppress MixedAssignment */
                    $sent = yield $currentEvent;
                    $turnGen->send($sent);
                }

                $awaitingClient = $turnGen->getReturn();
                if ($awaitingClient) {
                    // Run ends awaiting client execution; resume with resumeStream()
                    return;
                }

                if ($state->currentText !== '') {
                    $hadPreviousText = true;
                }

                continue;
            }

            // Any terminal stop reason without pending tools is returned as a completed stream.
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
        $this->pendingToolResults = [];
    }

    /**
     * Dispatch server tools, then hand remaining client tool calls to the consumer
     *
     * @return Generator<int, AgentEvent, mixed, bool> True when the run ends awaiting client execution
     *
     * @throws JsonException When the LLM produced malformed JSON for a client tool call —
     * the input would otherwise silently degrade to an empty array and be executed as such.
     */
    private function processToolUseTurn(StreamIterationState $state, ToolList $toolList): Generator
    {
        [$serverCalls, $clientCalls] = $this->partitionPendingToolCalls($state->pendingToolCalls, $toolList);

        $toolResults = [];
        if ($serverCalls !== []) {
            $dispatchGen = $this->dispatchPendingToolCalls($serverCalls, $state->currentText, $toolList);
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
        }

        if ($clientCalls !== []) {
            $this->pendingToolResults = $toolResults;
            foreach ($clientCalls as $pending) {
                /** @var array<string, mixed> $input */
                $input = (array) json_decode($pending->inputJson, true, 512, JSON_THROW_ON_ERROR);

                yield AgentEvent::clientToolCall($pending->name, $pending->id, $input);
            }

            return true;
        }

        $this->messages[] = Message::toolResults($toolResults);

        return false;
    }

    /**
     * @param list<PendingToolCall> $pendingToolCalls
     *
     * @return array{list<PendingToolCall>, list<PendingToolCall>} Server-dispatched calls and client-executed calls
     */
    private function partitionPendingToolCalls(array $pendingToolCalls, ToolList $toolList): array
    {
        $serverCalls = [];
        $clientCalls = [];
        foreach ($pendingToolCalls as $pending) {
            if ($toolList->isClient($pending->name)) {
                $clientCalls[] = $pending;

                continue;
            }

            $serverCalls[] = $pending;
        }

        return [$serverCalls, $clientCalls];
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

            if (! $toolList->has($toolCall->name)) {
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
