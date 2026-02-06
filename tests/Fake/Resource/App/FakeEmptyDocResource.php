<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class FakeEmptyDocResource extends ResourceObject
{
    /**
     * @param int $id
     */
    public function onGet(int $id): static
    {
        return $this;
    }
}
