<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class FakeMissingParamDocResource extends ResourceObject
{
    /**
     * Method with incomplete PHPDoc
     *
     * @param int $id The identifier
     */
    public function onGet(int $id, string $name): static
    {
        return $this;
    }
}
