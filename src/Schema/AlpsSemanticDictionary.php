<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use ArrayObject;
use Koriym\AppStateDiagram\Profile;
use Koriym\AppStateDiagram\SemanticDescriptor;

use function is_string;
use function property_exists;

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
        if (property_exists($descriptor, 'title') && is_string($descriptor->title) && $descriptor->title !== '') {
            return $descriptor->title;
        }

        // koriym/app-state-diagram extracts doc.value and stores it as a string
        if (property_exists($descriptor, 'doc') && is_string($descriptor->doc) && $descriptor->doc !== '') {
            return $descriptor->doc;
        }

        return null;
    }

    public function get(string $key): string|null
    {
        return $this[$key] ?? null;
    }
}
