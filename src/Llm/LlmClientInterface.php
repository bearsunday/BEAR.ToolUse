<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;

/**
 * Interface for LLM API clients
 */
interface LlmClientInterface
{
    /**
     * Send a chat request to the LLM
     *
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     */
    public function chat(
        string $system,
        array $messages,
        array $tools,
    ): LlmResponse;
}
