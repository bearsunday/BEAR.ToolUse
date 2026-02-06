<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class FakeSearchResource extends ResourceObject
{
    public function onGet(string $query, int $limit = 10): static
    {
        return $this;
    }
}
