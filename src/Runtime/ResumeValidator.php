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
 * client tool_use blocks of the trailing assistant message. Deriving them
 * from messages rather than from instance state keeps stateless resumption
 * possible — a consumer may reconstruct the conversation on a fresh agent
 * instance across HTTP requests and still resume.
 *
 * Server tool results are never accepted from resume input: they are
 * computed server-side and held in the agent instance for the interrupted
 * turn. A trailing assistant message with server tool_use blocks that have
 * no held results is rejected — when resuming a mixed turn statelessly,
 * rebuild the trailing assistant message with the client tool_use blocks
 * only.
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
     * a server call of the interrupted turn lacks its held result, or the supplied
     * result IDs do not match the awaited client calls exactly once each.
     */
    public static function validate(array $messages, ToolList $toolList, array $heldResults, array $toolResults): void
    {
        [$expected, $unresolvedServer] = self::awaitedIds($messages, $toolList, $heldResults);
        if ($expected === []) {
            throw new InvalidResumeException('No client tool calls are awaiting results');
        }

        if ($unresolvedServer !== []) {
            throw new InvalidResumeException(sprintf(
                'Server tool calls [%s] have no held results. Server results cannot be supplied on resume;'
                . ' when resuming statelessly, rebuild the trailing assistant message with the client'
                . ' tool_use blocks only',
                implode(', ', array_keys($unresolvedServer)),
            ));
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
     * Partition the trailing assistant message's tool_use IDs
     *
     * @param list<Message>    $messages
     * @param list<ToolResult> $heldResults
     *
     * @return array{array<string, true>, array<string, true>} Awaited client IDs and server IDs without a held result
     */
    private static function awaitedIds(array $messages, ToolList $toolList, array $heldResults): array
    {
        $lastMessage = end($messages);
        if (! $lastMessage instanceof Message || $lastMessage->role !== 'assistant') {
            return [[], []];
        }

        $client = [];
        $server = [];
        foreach ($lastMessage->content as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $id = $block['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            if (self::isClientBlock($block, $toolList)) {
                $client[$id] = true;

                continue;
            }

            // A block without a client tool name is a server call
            $server[$id] = true;
        }

        // A held result resolves its call, whichever side it was classified on
        foreach ($heldResults as $held) {
            unset($client[$held->toolUseId], $server[$held->toolUseId]);
        }

        return [$client, $server];
    }

    /** @param array<string, mixed> $block */
    private static function isClientBlock(array $block, ToolList $toolList): bool
    {
        return isset($block['name']) && is_string($block['name']) && $toolList->isClient($block['name']);
    }
}
