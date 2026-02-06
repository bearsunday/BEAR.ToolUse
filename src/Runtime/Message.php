<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\ToolResult;

use function array_map;

/**
 * Message in conversation
 */
final readonly class Message
{
    /** @param list<array<string, mixed>> $content */
    private function __construct(
        public string $role,
        public array $content,
    ) {
    }

    public static function user(string $text): self
    {
        return new self('user', [['type' => 'text', 'text' => $text]]);
    }

    /** @param list<array<string, mixed>> $content */
    public static function assistant(array $content): self
    {
        return new self('assistant', $content);
    }

    /** @param list<ToolResult> $results */
    public static function toolResults(array $results): self
    {
        $content = array_map(
            static fn (ToolResult $r): array => [
                'type' => 'tool_result',
                'tool_use_id' => $r->toolUseId,
                'content' => $r->content,
                'is_error' => $r->isError,
            ],
            $results,
        );

        return new self('user', $content);
    }

    /** @return array{role: string, content: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }
}
