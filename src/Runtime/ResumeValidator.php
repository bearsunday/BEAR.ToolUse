<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\ToolResult;

use function array_diff_key;
use function array_keys;
use function end;
use function implode;
use function is_string;
use function sprintf;

/**
 * Validates that a resume call matches the client tool calls awaiting results
 *
 * The expected result IDs are derived from the conversation itself: the
 * tool_use blocks of the trailing assistant message, minus the server-side
 * results already held for the interrupted turn. Deriving them from messages
 * rather than from instance state keeps stateless resumption possible — a
 * consumer may reconstruct the conversation on a fresh agent instance across
 * HTTP requests and still resume.
 *
 * This is a fail-fast correctness check, not a trust boundary: a consumer
 * that accepts conversation history from a client must authenticate the
 * request and bind it to a server-side session. See "Security
 * Considerations" in the README.
 */
final readonly class ResumeValidator
{
    /**
     * @param list<Message>    $messages    Conversation so far
     * @param list<ToolResult> $heldResults Server-side results held for the interrupted turn
     * @param list<ToolResult> $toolResults Client execution results being supplied
     *
     * @throws InvalidResumeException When no client tool calls are awaiting results,
     * or the supplied result IDs do not match the awaited calls exactly once each.
     */
    public static function validate(array $messages, array $heldResults, array $toolResults): void
    {
        $expected = self::expectedIds($messages, $heldResults);
        if ($expected === []) {
            throw new InvalidResumeException('No client tool calls are awaiting results');
        }

        $supplied = [];
        foreach ($toolResults as $result) {
            if (isset($supplied[$result->toolUseId])) {
                throw new InvalidResumeException(sprintf('Duplicate tool result for id "%s"', $result->toolUseId));
            }

            $supplied[$result->toolUseId] = true;
        }

        $missing = array_diff_key($expected, $supplied);
        $unexpected = array_diff_key($supplied, $expected);
        if ($missing !== [] || $unexpected !== []) {
            throw new InvalidResumeException(sprintf(
                'Tool results do not match awaited client tool calls (missing: [%s], unexpected: [%s])',
                implode(', ', array_keys($missing)),
                implode(', ', array_keys($unexpected)),
            ));
        }
    }

    /**
     * @param list<Message>    $messages
     * @param list<ToolResult> $heldResults
     *
     * @return array<string, true>
     */
    private static function expectedIds(array $messages, array $heldResults): array
    {
        $lastMessage = end($messages);
        if (! $lastMessage instanceof Message || $lastMessage->role !== 'assistant') {
            return [];
        }

        $expected = [];
        foreach ($lastMessage->content as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $id = $block['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            $expected[$id] = true;
        }

        foreach ($heldResults as $held) {
            unset($expected[$held->toolUseId]);
        }

        return $expected;
    }
}
