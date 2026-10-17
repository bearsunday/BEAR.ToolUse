<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use JsonException;

use function is_array;
use function json_decode;
use function ltrim;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * Tool call accumulated during streaming, before its JSON input is decoded
 */
final readonly class PendingToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $inputJson,
    ) {
    }

    /**
     * Decode the streamed JSON into tool arguments
     *
     * A call that streamed no input at all is an argument-less call. Anything
     * else must be a JSON object. The container type is read from the text:
     * json_decode() maps both {} and [] to the same empty PHP array and turns
     * {"0": ...} into a list, so the decoded value cannot tell them apart.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException When the input is malformed, or valid JSON that is not an object.
     */
    public function input(): array
    {
        if ($this->inputJson === '') {
            return [];
        }

        /** @var mixed $input */
        $input = json_decode($this->inputJson, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($input) || ! str_starts_with(ltrim($this->inputJson), '{')) {
            throw new JsonException('Tool arguments must be a JSON object');
        }

        /** @var array<string, mixed> $input */
        return $input;
    }
}
