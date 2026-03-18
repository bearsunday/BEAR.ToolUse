<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use Override;

/**
 * Fake streaming LLM client that yields preset stream events
 */
class FakeStreamingLlmClient implements StreamingLlmClientInterface
{
    /** @var list<list<StreamEvent>> */
    private array $eventSequences = [];
    private int $callCount = 0;

    /** @var list<array{system: string, messages: list<Message>, tools: list<Tool>}> */
    public array $calls = [];

    /** @param list<list<StreamEvent>> $sequences */
    public function setEventSequences(array $sequences): void
    {
        $this->eventSequences = $sequences;
        $this->callCount = 0;
        $this->calls = [];
    }

    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return Generator<int, StreamEvent, void>
     */
    #[Override]
    public function chatStream(string $system, array $messages, array $tools): Generator
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages, 'tools' => $tools];

        $events = $this->eventSequences[$this->callCount] ?? [
            new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'No more events configured']),
            new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
            new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
        ];
        $this->callCount++;

        foreach ($events as $event) {
            yield $event;
        }
    }
}
