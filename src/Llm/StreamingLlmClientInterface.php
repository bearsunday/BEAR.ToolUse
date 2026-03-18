<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use Generator;

/**
 * Interface for streaming LLM API clients
 *
 * @psalm-type StreamGenerator = Generator<int, StreamEvent, mixed, void>
 */
interface StreamingLlmClientInterface
{
    /**
     * Send a streaming chat request to the LLM
     *
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    public function chatStream(
        string $system,
        array $messages,
        array $tools,
    ): Generator;
}
