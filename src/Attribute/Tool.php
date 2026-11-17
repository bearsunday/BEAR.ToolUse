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
     * @param string|null $name        Read on a method only; null names the tool `<path>_<method>`, e.g. `user_get`, where
     *                                 `<path>` is the resource path with `/` and `-` turned into `_`, e.g. `admin_users`
     *                                 for `/admin/users`. The HTTP method of the call is read back from the name: the
     *                                 rest after `<path>_` (`admin_users_export_post` gives `export_post`), else a
     *                                 `_get`/`_post`/`_put`/`_patch`/`_delete` suffix, else GET
     * @param string|null $description null takes the class value, then the summary of the method docblock
     * @param bool|null   $confirm     true marks the tool as needing confirmation: Agent asks ConfirmationHandlerInterface
     *                                 when one is set and runs the tool without asking when none is set; StreamingAgent
     *                                 yields a confirmationRequired event; null takes the class value, then false
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
