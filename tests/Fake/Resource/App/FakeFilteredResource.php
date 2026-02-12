<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;
use BEAR\ToolUse\Fake\FakeSummaryFilter;

#[Tool(filter: FakeSummaryFilter::class)]
class FakeFilteredResource extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = [
            ['id' => $id, 'title' => 'Article 1', 'body' => 'Long body text...', 'metadata' => ['views' => 100]],
        ];

        return $this;
    }

    public function onDelete(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'DELETE'];

        return $this;
    }
}
