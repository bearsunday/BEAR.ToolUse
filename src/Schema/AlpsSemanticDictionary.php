<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use ArrayObject;
use Koriym\AppStateDiagram\Profile;
use Koriym\AppStateDiagram\SemanticDescriptor;


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

        // koriym/app-state-diagram extracts doc.value and stores it as a string
        if ($descriptor->doc !== '') {
            return $descriptor->doc;
        }

        return null;
    }

    public function get(string $key): string|null
    {
        return $this[$key] ?? null;
    }
}
