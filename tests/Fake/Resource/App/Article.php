<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class Article extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = [
            'id' => $id,
            'title' => 'Test Article ' . $id,
            'body' => 'This is the content of article ' . $id,
        ];

        return $this;
    }
}
