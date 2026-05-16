<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\Tool;
use Override;

use function in_array;
use function lcfirst;
use function str_replace;
use function ucwords;

/** Filters available tools by matching ALPS descriptor types */
final readonly class AlpsToolPolicyInputProcessor implements InputProcessorInterface
{
    public const UNKNOWN_TOOLS_HIDDEN = 'hidden';
    public const UNKNOWN_TOOLS_ALLOWED = 'allowed';

    /**
     * @param list<string>                                           $allowedTypes
     * @param self::UNKNOWN_TOOLS_HIDDEN|self::UNKNOWN_TOOLS_ALLOWED $unknownToolPolicy
     */
    public function __construct(
        private AlpsSemanticDictionary $dictionary,
        private array $allowedTypes,
        private string $unknownToolPolicy = self::UNKNOWN_TOOLS_HIDDEN,
    ) {
    }

    public static function safeOnly(AlpsSemanticDictionary $dictionary): self
    {
        return new self($dictionary, ['safe', 'idempotent']);
    }

    public static function safeOnlyAllowingUnknownTools(AlpsSemanticDictionary $dictionary): self
    {
        return new self($dictionary, ['safe', 'idempotent'], self::UNKNOWN_TOOLS_ALLOWED);
    }

    #[Override]
    public function process(LlmRequest $request): LlmRequest
    {
        $tools = [];
        foreach ($request->tools as $tool) {
            if (! $this->allows($tool)) {
                continue;
            }

            $tools[] = $tool;
        }

        if ($tools === $request->tools) {
            return $request;
        }

        return $request->withTools($tools);
    }

    private function allows(Tool $tool): bool
    {
        $descriptor = $this->resolveDescriptor($tool->name);
        if ($descriptor === null) {
            return $this->unknownToolPolicy === self::UNKNOWN_TOOLS_ALLOWED;
        }

        return in_array($descriptor['type'], $this->allowedTypes, true);
    }

    /** @return array{id: string, type: string, description: string|null, href: string|null}|null */
    private function resolveDescriptor(string $id): array|null
    {
        $descriptor = $this->dictionary->getDescriptor($id);
        if ($descriptor !== null) {
            return $descriptor;
        }

        return $this->dictionary->getDescriptor($this->toCamelCase($id));
    }

    private function toCamelCase(string $id): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $id))));
    }
}
