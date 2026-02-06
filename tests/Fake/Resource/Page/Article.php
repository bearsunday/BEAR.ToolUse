<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\Page;

use BEAR\Resource\ResourceObject;

class Article extends ResourceObject
{
    /**
     * Get an article page by ID
     */
    public function onGet(int $id): static
    {
        $this->body = [
            'id' => $id,
            'title' => 'Article Page',
            'scheme' => 'page',
        ];

        return $this;
    }
}
