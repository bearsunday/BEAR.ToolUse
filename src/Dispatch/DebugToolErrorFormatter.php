<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Discloses everything: exception class and message, status code and body
 *
 * For development, where the error feedback loop is easier to follow when the
 * raw failure is visible to the LLM. Bind it in place of ToolErrorFormatter:
 *
 *   $this->bind(ToolErrorFormatterInterface::class)->to(DebugToolErrorFormatter::class);
 */
final readonly class DebugToolErrorFormatter implements ToolErrorFormatterInterface
{
    #[Override]
    public function fromStatus(ResourceObject $ro): string
    {
        $body = json_encode($ro->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sprintf('%d: %s', $ro->code, $body);
    }

    #[Override]
    public function fromThrowable(Throwable $e): string
    {
        return sprintf('%s: %s', $e::class, $e->getMessage());
    }
}
