<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use ArrayObject;
use Koriym\AppStateDiagram\Profile;
use Koriym\AppStateDiagram\SemanticDescriptor;
use stdClass;

use function is_string;

/**
 * ALPS semantic dictionary for description enrichment
 *
 * @extends ArrayObject<string, string>
 */
final class AlpsSemanticDictionary extends ArrayObject
{
    public function __construct(Profile $profile)
    {
        $dictionary = [];
        foreach ($profile->descriptors as $descriptor) {
            if (! $descriptor instanceof SemanticDescriptor) {
                continue; // @codeCoverageIgnore
            }

            $description = $this->extractDescription($descriptor);
            if ($description === null) {
                continue;
            }

            $dictionary[$descriptor->id] = $description;
        }

        parent::__construct($dictionary);
    }

    private function extractDescription(SemanticDescriptor $descriptor): string|null
    {
        if ($descriptor->title !== '') {
            return $descriptor->title;
        }

        // v0.17+ stores doc as string, v0.8 stores as stdClass with value property
        if (is_string($descriptor->doc) && $descriptor->doc !== '') {
            return $descriptor->doc;
        }

        if ($descriptor->doc instanceof stdClass && isset($descriptor->doc->value)) {
            return (string) $descriptor->doc->value;
        }

        return null;
    }

    public function get(string $key): string|null
    {
        return $this[$key] ?? null;
    }
}
