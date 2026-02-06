<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * Resource for testing various type mappings
 */
class FakeTypesResource extends ResourceObject
{
    /**
     * Test various types
     */
    public function onGet(
        float $price,
        bool $active,
        array $items,
        $noType,
    ): static {
        $this->body = [];

        return $this;
    }
}
