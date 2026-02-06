<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use function implode;
use function is_array;
use function is_string;

/**
 * Response from agent execution
 */
final readonly class AgentResponse
{
    public const STOP_COMPLETED = 'completed';
    public const STOP_MAX_ITERATIONS = 'max_iterations';
    public const STOP_MAX_TOKENS = 'max_tokens';
    public const STOP_STOP_SEQUENCE = 'stop_sequence';

    /** @param list<Message> $messages */
    private function __construct(
        public bool $completed,
        public string $stopReason,
        public mixed $content,
        public array $messages,
    ) {
    }

    public static function completed(mixed $content): self
    {
        return new self(true, self::STOP_COMPLETED, $content, []);
    }

    /** @param list<Message> $messages */
    public static function maxIterationsReached(array $messages): self
    {
        return new self(false, self::STOP_MAX_ITERATIONS, null, $messages);
    }

    /** @param list<Message> $messages */
    public static function maxTokensReached(mixed $content, array $messages): self
    {
        return new self(false, self::STOP_MAX_TOKENS, $content, $messages);
    }

    /** @param list<Message> $messages */
    public static function stopSequenceReached(mixed $content, array $messages): self
    {
        return new self(false, self::STOP_STOP_SEQUENCE, $content, $messages);
    }

    public function getText(): string
    {
        if (! is_array($this->content)) {
            return '';
        }

        /** @var list<string> $texts */
        $texts = [];
        foreach ($this->content as $block) {
            if (! is_array($block) || ! (($block['type'] ?? '') === 'text') || ! isset($block['text'])) {
                continue;
            }

            $text = $block['text'];
            if (! is_string($text)) {
                continue;
            }

            $texts[] = $text;
        }

        return implode("\n", $texts);
    }
}
