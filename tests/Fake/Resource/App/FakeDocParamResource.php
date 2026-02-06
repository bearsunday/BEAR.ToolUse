<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * Resource with PHPDoc @param descriptions
 */
class FakeDocParamResource extends ResourceObject
{
    /**
     * Get something with parameters
     *
     * @param int $id The unique identifier
     * @param string $name The display name for this item
     */
    public function onGet(int $id, string $name): static
    {
        return $this;
    }
}
