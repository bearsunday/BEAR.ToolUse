<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\Tool;

/**
 * Request passed to an LLM call
 */
final readonly class LlmRequest
{
    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools
     */
    public function __construct(
        public string $systemPrompt,
        public array $messages,
        public array $tools,
    ) {
    }

    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self($systemPrompt, $this->messages, $this->tools);
    }

    /** @param list<Message> $messages */
    public function withMessages(array $messages): self
    {
        return new self($this->systemPrompt, $messages, $this->tools);
    }

    /** @param list<Tool> $tools */
    public function withTools(array $tools): self
    {
        return new self($this->systemPrompt, $this->messages, $tools);
    }
}
