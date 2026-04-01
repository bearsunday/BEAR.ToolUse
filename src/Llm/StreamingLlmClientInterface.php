<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;
use BEAR\ToolUse\Types;
use Generator;

/**
 * Interface for streaming LLM API clients
 *
 * @psalm-import-type StreamGenerator from Types
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
