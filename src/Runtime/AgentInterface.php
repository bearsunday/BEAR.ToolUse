<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

/**
 * Agent runtime for managing LLM conversation loop
 */
interface AgentInterface
{
    /**
     * Run the agent with a user message
     */
    public function run(string $userMessage): AgentResponse;

    /**
     * Clear conversation history to start a new conversation
     */
    public function reset(): void;

    /**
     * Get all messages in the conversation
     *
     * @return list<Message>
     */
    public function getMessages(): array;
}
