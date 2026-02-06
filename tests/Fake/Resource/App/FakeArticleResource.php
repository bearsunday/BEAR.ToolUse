<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class FakeArticleResource extends ResourceObject
{
    /**
     * Get an article by ID
     */
    public function onGet(int $id): static
    {
        return $this;
    }
}
