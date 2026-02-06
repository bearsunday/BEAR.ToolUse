<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class MyArticle extends ResourceObject
{
    /**
     * Get an article with hyphenated path
     */
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'title' => 'My Article'];

        return $this;
    }
}
