<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;
use BEAR\ToolUse\Fake\FakeSummaryFilter;

class FakeMethodFilterResource extends ResourceObject
{
    #[Tool(filter: FakeSummaryFilter::class)]
    public function onGet(int $id): static
    {
        $this->body = [
            ['id' => $id, 'title' => 'Article 1', 'body' => 'Long body text...'],
        ];

        return $this;
    }

    public function onPost(string $name): static
    {
        $this->body = ['name' => $name, 'method' => 'POST'];

        return $this;
    }
}
