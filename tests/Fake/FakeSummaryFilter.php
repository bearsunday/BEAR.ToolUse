<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake;

use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;
use Override;

use function array_map;
use function is_array;

/**
 * Extracts only id and title from result items
 */
final readonly class FakeSummaryFilter implements ToolResultFilterInterface
{
    /** @return list<array{id: mixed, title: mixed}>|mixed */
    #[Override]
    public function __invoke(mixed $body): mixed
    {
        if (! is_array($body)) {
            return $body;
        }

        return array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'title' => $item['title'],
        ], $body);
    }
}
