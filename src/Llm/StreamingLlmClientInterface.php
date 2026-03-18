<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use Generator;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;

/**
 * Interface for streaming LLM API clients
 *
 * @psalm-type StreamGenerator = Generator<int, StreamEvent, void>
 */
interface StreamingLlmClientInterface
{
    /**
     * Send a streaming chat request to the LLM
     *
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     *
     * @return Generator<int, StreamEvent, void>
     */
    public function chatStream(
        string $system,
        array $messages,
        array $tools,
    ): Generator;
}
