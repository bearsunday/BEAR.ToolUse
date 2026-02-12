<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;
use BEAR\ToolUse\Fake\FakeSummaryFilter;

/**
 * Class-level filter with method-level #[Tool] that doesn't override filter
 */
#[Tool(filter: FakeSummaryFilter::class)]
class FakeFilterInheritResource extends ResourceObject
{
    #[Tool(name: 'custom_filtered_get')]
    public function onGet(int $id): static
    {
        $this->body = [
            ['id' => $id, 'title' => 'Article 1', 'body' => 'Long body text...'],
        ];

        return $this;
    }

    public function onDelete(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'DELETE'];

        return $this;
    }
}
