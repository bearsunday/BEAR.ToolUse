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

    /** @return Generator<int, AgentEvent, void> */
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

            $currentText = '';
            $needsSeparator = $hadPreviousText;
            $stopReason = 'end_turn';
            /** @var list<array{id: string, name: string, inputJson: string}> $pendingToolCalls */
            $pendingToolCalls = [];
            $currentToolId = '';
            $currentToolName = '';
            $currentToolInputJson = '';
            /** @var list<array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}> $contentBlocks */
            $contentBlocks = [];

            foreach ($stream as $event) {
                switch ($event->type) {
                    case StreamEvent::TEXT_DELTA:
                        $text = (string) ($event->data['text'] ?? '');
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
                        $currentToolId = (string) ($event->data['id'] ?? '');
                        $currentToolName = (string) ($event->data['name'] ?? '');
                        $currentToolInputJson = '';

                        yield AgentEvent::toolStart($currentToolName);

                        break;

                    case StreamEvent::TOOL_USE_DELTA:
                        $currentToolInputJson .= (string) ($event->data['input'] ?? '');

                        break;

                    case StreamEvent::CONTENT_BLOCK_STOP:
                        if ($currentToolName !== '') {
                            $pendingToolCalls[] = [
                                'id' => $currentToolId,
                                'name' => $currentToolName,
                                'inputJson' => $currentToolInputJson,
                            ];
                            /** @var array<string, mixed> $input */
                            $input = json_decode($currentToolInputJson, true) ?: [];
                            $contentBlocks[] = [
                                'type' => 'tool_use',
                                'id' => $currentToolId,
                                'name' => $currentToolName,
                                'input' => $input,
                            ];
                            $currentToolId = '';
                            $currentToolName = '';
                            $currentToolInputJson = '';
                        } elseif ($currentText !== '') {
                            $contentBlocks[] = ['type' => 'text', 'text' => $currentText];
                        }

                        break;

                    case StreamEvent::MESSAGE_STOP:
                        $stopReason = (string) ($event->data['stopReason'] ?? 'end_turn');

                        break;
                }
            }

            if ($stopReason === 'end_turn') {
                if ($contentBlocks !== []) {
                    $this->messages[] = Message::assistant($contentBlocks);
                }

                yield AgentEvent::completed($fullText);

                return;
            }

            if ($stopReason === 'tool_use' && $pendingToolCalls !== []) {
                $this->messages[] = Message::assistant($contentBlocks);

                $toolResults = [];
                foreach ($pendingToolCalls as $pending) {
                    /** @var array<string, mixed> $input */
                    $input = json_decode($pending['inputJson'], true) ?: [];
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

                $this->messages[] = Message::toolResults($toolResults);
                if ($currentText !== '') {
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
}
