<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Types;
use JsonException;

use function array_is_list;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Accumulates stream events into content blocks and iteration state
 *
 * @psalm-import-type ContentBlock from Types
 */
final class StreamContentAccumulator
{
    /** Text of the block being assembled; cleared at every content-block boundary */
    private string $blockText = '';

    /** Text of the whole turn; kept for the confirmation prompt and iteration state */
    private string $currentText = '';
    private bool $needsSeparator;
    private string $stopReason = 'end_turn';
    private string $currentToolId = '';
    private string $currentToolName = '';
    private string $currentToolInputJson = '';
    private string $currentReasoning = '';
    private string $currentReasoningSignature = '';
    private string $currentRedactedReasoning = '';

    /** @var list<PendingToolCall> */
    private array $pendingToolCalls = [];

    /** @var list<ContentBlock> */
    private array $contentBlocks = [];

    public function __construct(bool $hadPreviousText, private string $fullText)
    {
        $this->needsSeparator = $hadPreviousText;
    }

    /** @return list<AgentEvent> */
    public function handleEvent(StreamEvent $event): array
    {
        return match ($event->type) {
            StreamEvent::TEXT_DELTA => $this->handleTextDelta($event),
            StreamEvent::REASONING_DELTA => $this->handleReasoningDelta($event),
            StreamEvent::REASONING_SIGNATURE => $this->handleReasoningSignature($event),
            StreamEvent::REASONING_REDACTED => $this->handleReasoningRedacted($event),
            StreamEvent::TOOL_USE_START => $this->handleToolUseStart($event),
            StreamEvent::TOOL_USE_DELTA => $this->handleToolUseDelta($event),
            StreamEvent::CONTENT_BLOCK_STOP => $this->handleContentBlockStop(),
            StreamEvent::MESSAGE_STOP => $this->handleMessageStop($event),
            default => [],
        };
    }

    public function toState(): StreamIterationState
    {
        return new StreamIterationState(
            stopReason: $this->stopReason,
            currentText: $this->currentText,
            pendingToolCalls: $this->pendingToolCalls,
            contentBlocks: $this->contentBlocks,
            fullText: $this->fullText,
        );
    }

    /** @return list<AgentEvent> */
    private function handleTextDelta(StreamEvent $event): array
    {
        $text = $this->eventString($event, 'text');
        $events = [];

        if ($this->needsSeparator) {
            $this->fullText .= "\n";
            $events[] = AgentEvent::textDelta("\n");
            $this->needsSeparator = false;
        }

        $this->blockText .= $text;
        $this->currentText .= $text;
        $this->fullText .= $text;
        $events[] = AgentEvent::textDelta($text);

        return $events;
    }

    /**
     * Reasoning text is not surfaced as an AgentEvent: callers that display
     * agent output must not leak the model's internal reasoning by default.
     * It is kept only to be replayed to the model on the next request.
     *
     * @return list<AgentEvent>
     */
    private function handleReasoningDelta(StreamEvent $event): array
    {
        $this->currentReasoning .= $this->eventString($event, 'text');

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleReasoningSignature(StreamEvent $event): array
    {
        $this->currentReasoningSignature = $this->eventString($event, 'signature');

        return [];
    }

    /**
     * Safety-redacted reasoning arrives as opaque encrypted data instead of text.
     * It is a distinct block type that must round-trip unchanged, so it is kept
     * apart from regular reasoning rather than folded into it.
     *
     * @return list<AgentEvent>
     */
    private function handleReasoningRedacted(StreamEvent $event): array
    {
        $this->currentRedactedReasoning .= $this->eventString($event, 'data');

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleToolUseStart(StreamEvent $event): array
    {
        $this->currentToolId = $this->eventString($event, 'id');
        $this->currentToolName = $this->eventString($event, 'name');
        $this->currentToolInputJson = '';

        return [AgentEvent::toolStart($this->currentToolName)];
    }

    /** @return list<AgentEvent> */
    private function handleToolUseDelta(StreamEvent $event): array
    {
        $this->currentToolInputJson .= $this->eventString($event, 'input');

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleContentBlockStop(): array
    {
        $this->finalizeContentBlock();
        $this->blockText = '';
        $this->currentToolId = '';
        $this->currentToolName = '';
        $this->currentToolInputJson = '';
        $this->currentReasoning = '';
        $this->currentReasoningSignature = '';
        $this->currentRedactedReasoning = '';

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleMessageStop(StreamEvent $event): array
    {
        $this->stopReason = $this->eventString($event, 'stopReason', 'end_turn');

        return [];
    }

    private function finalizeContentBlock(): void
    {
        if ($this->currentRedactedReasoning !== '') {
            $this->contentBlocks[] = [
                'type' => 'redacted_reasoning',
                'data' => $this->currentRedactedReasoning,
            ];

            return;
        }

        // A reasoning block carries a signature even when its text is withheld
        // by the provider, and the model needs both back to continue the turn
        if ($this->currentReasoning !== '' || $this->currentReasoningSignature !== '') {
            $this->contentBlocks[] = [
                'type' => 'reasoning',
                'text' => $this->currentReasoning,
                'signature' => $this->currentReasoningSignature,
            ];

            return;
        }

        if ($this->currentToolName !== '') {
            $this->pendingToolCalls[] = new PendingToolCall(
                $this->currentToolId,
                $this->currentToolName,
                $this->currentToolInputJson,
            );
            $this->contentBlocks[] = [
                'type' => 'tool_use',
                'id' => $this->currentToolId,
                'name' => $this->currentToolName,
                'input' => $this->decodeToolInput(),
            ];

            return;
        }

        if ($this->blockText === '') {
            return;
        }

        $this->contentBlocks[] = ['type' => 'text', 'text' => $this->blockText];
    }

    /**
     * Decode the tool input for the assistant message kept in history
     *
     * The API requires an object for tool_use.input, and a PHP empty array
     * encodes as [] rather than {}. Empty, malformed, or non-object input is
     * therefore recorded as an empty object. It is not executed as such:
     * StreamingAgent decodes the raw JSON again when dispatching and pairs
     * this block with an error result that carries the raw text, so the model
     * sees what it actually emitted.
     *
     * @return array<string, mixed>|object
     */
    private function decodeToolInput(): array|object
    {
        if ($this->currentToolInputJson === '') {
            return (object) [];
        }

        try {
            /** @var mixed $input */
            $input = json_decode($this->currentToolInputJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return (object) [];
        }

        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            return (object) [];
        }

        /** @var array<string, mixed> $input */
        return $input === [] ? (object) [] : $input;
    }

    private function eventString(StreamEvent $event, string $key, string $default = ''): string
    {
        $value = $event->data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
