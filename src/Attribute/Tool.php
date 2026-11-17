<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Attribute;

use Attribute;
use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;

/**
 * Sets the name, description, confirmation and result filter of the tools generated from the resource
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/ai-assistant.html#beartooluse
 * @codeCoverageIgnore
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Tool
{
    /**
     * @param string|null $name        Read on a method only; null names the tool `<resource path>_<method>`, e.g. `user_get`
     * @param string|null $description null takes the class value, then the summary of the method docblock
     * @param bool|null   $confirm     true asks ConfirmationHandlerInterface before the call; null takes the class value, then false
     */
    public function __construct(
        public string|null $name = null,
        public string|null $description = null,
        public bool|null $confirm = null,
        /** @var class-string<ToolResultFilterInterface>|null */
        public string|null $filter = null,
    ) {
    }
}
