<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\Tool;
use Override;

use function array_keys;
use function array_slice;
use function implode;
use function lcfirst;
use function str_replace;
use function ucwords;

/**
 * Adds ALPS semantic context for the tools available in an LLM call.
 *
 * Tool descriptions are resolved from ALPS descriptors whose id matches the tool
 * name (or its camelCase form), including transition descriptors such as `safe`
 * and `unsafe`. Parameter descriptions are resolved only from semantic
 * descriptors whose id matches the input parameter name.
 *
 * The context is merged into the trailing user message rather than appended as a
 * message of its own: inside a tool loop that message carries the `tool_result`
 * blocks answering the previous turn, and a separate message after it would
 * break the tool-result turn.
 */
final readonly class AlpsContextInputProcessor implements InputProcessorInterface
{
    public function __construct(
        private AlpsSemanticDictionary $dictionary,
        private string $heading = 'Application semantics from ALPS:',
    ) {
    }

    #[Override]
    public function process(LlmRequest $request): LlmRequest
    {
        $lines = $this->buildLines($request->tools);
        if ($lines === []) {
            return $request;
        }

        $text = $this->heading . "\n" . implode("\n", $lines);
        $messages = $request->messages;
        $lastMessage = array_slice($messages, -1)[0] ?? null;
        if ($lastMessage === null || $lastMessage->role !== 'user') {
            return $request->withMessages([...$messages, Message::user($text)]);
        }

        return $request->withMessages([
            ...array_slice($messages, 0, -1),
            $lastMessage->withText($text),
        ]);
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private function buildLines(array $tools): array
    {
        $lines = [];
        $seen = [];
        foreach ($tools as $tool) {
            $this->addToolLine($tool, $lines, $seen);
            $this->addParameterLines($tool, $lines, $seen);
        }

        return $lines;
    }

    /**
     * @param list<string>        $lines
     * @param array<string, true> $seen
     */
    private function addToolLine(Tool $tool, array &$lines, array &$seen): void
    {
        $descriptor = $this->resolveDescriptor($tool->name);
        if ($descriptor === null || $descriptor['description'] === null) {
            return;
        }

        $key = $tool->name;
        if ($descriptor['type'] !== 'semantic') {
            $key .= ' [' . $descriptor['type'] . ']';
        }

        $this->addLine($key, $descriptor['description'], $lines, $seen);
    }

    /**
     * @param list<string>        $lines
     * @param array<string, true> $seen
     */
    private function addParameterLines(Tool $tool, array &$lines, array &$seen): void
    {
        foreach (array_keys($tool->inputSchema['properties']) as $paramName) {
            $description = $this->resolveDescription($paramName);
            if ($description === null) {
                continue;
            }

            $this->addLine($tool->name . '.' . $paramName, $description, $lines, $seen);
        }
    }

    private function resolveDescription(string $id): string|null
    {
        $description = $this->dictionary->get($id);
        if ($description !== null) {
            return $description;
        }

        return $this->dictionary->get($this->toCamelCase($id));
    }

    /** @return array{id: string, type: string, description: string|null, href: string|null}|null */
    private function resolveDescriptor(string $id): array|null
    {
        $descriptor = $this->dictionary->getDescriptor($id);
        if ($descriptor !== null) {
            return $descriptor;
        }

        return $this->dictionary->getDescriptor($this->toCamelCase($id));
    }

    private function toCamelCase(string $id): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $id))));
    }

    /**
     * @param list<string>        $lines
     * @param array<string, true> $seen
     */
    private function addLine(string $key, string $description, array &$lines, array &$seen): void
    {
        if (isset($seen[$key])) {
            return;
        }

        $lines[] = '- ' . $key . ': ' . $description;
        $seen[$key] = true;
    }
}
