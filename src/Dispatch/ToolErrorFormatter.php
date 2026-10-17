<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

use function is_int;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Default formatter: discloses client errors, hides server errors
 *
 * Follows the HTTP convention. A 4xx response, or an exception carrying a 4xx
 * code such as BEAR.Resource's BadRequestException family, was written for the
 * caller, so its body or message is passed on and the LLM can correct its call.
 * Anything else is an internal failure: the LLM learns only that the tool
 * failed and, for a status error, which code. Exception classes, messages,
 * and 5xx bodies stay out of the conversation.
 */
final readonly class ToolErrorFormatter implements ToolErrorFormatterInterface
{
    private const CLIENT_ERROR_MIN = 400;
    private const SERVER_ERROR_MIN = 500;
    private const FAILED = 'Tool execution failed.';

    #[Override]
    public function fromStatus(ResourceObject $ro): string
    {
        if ($ro->code >= self::SERVER_ERROR_MIN) {
            return sprintf('%d: %s', $ro->code, self::FAILED);
        }

        $body = json_encode($ro->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sprintf('%d: %s', $ro->code, $body);
    }

    #[Override]
    public function fromThrowable(Throwable $e): string
    {
        $code = $e->getCode();
        if (is_int($code) && $code >= self::CLIENT_ERROR_MIN && $code < self::SERVER_ERROR_MIN) {
            return sprintf('%d: %s', $code, $e->getMessage());
        }

        return self::FAILED;
    }
}
