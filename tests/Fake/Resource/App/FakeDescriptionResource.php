<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

/**
 * This is the docblock description
 */
#[Tool(description: 'Class level description')]
class FakeDescriptionResource extends ResourceObject
{
    /**
     * Method docblock description
     */
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }

    #[Tool(description: 'Method attribute description')]
    public function onPost(): static
    {
        $this->body = [];

        return $this;
    }

    public function onPut(): static
    {
        $this->body = [];

        return $this;
    }
}
