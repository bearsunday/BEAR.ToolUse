<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use ArrayObject;
use InvalidArgumentException;
use SimpleXMLElement;
use stdClass;

use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function pathinfo;
use function simplexml_load_string;
use function sprintf;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;

/**
 * ALPS semantic dictionary for description enrichment
 *
 * Parses an ALPS profile (JSON or XML) and exposes an
 * `id` => description mapping for semantic descriptors.
 *
 * @extends ArrayObject<string, string>
 */
final class AlpsSemanticDictionary extends ArrayObject
{
    public function __construct(string $profilePath)
    {
        $extension = strtolower(pathinfo($profilePath, PATHINFO_EXTENSION));
        $dictionary = match ($extension) {
            'json' => $this->loadJson($profilePath),
            'xml' => $this->loadXml($profilePath),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported ALPS profile format: .%s', $extension),
            ),
        };

        parent::__construct($dictionary);
    }

    public function get(string $key): string|null
    {
        return $this[$key] ?? null;
    }

    /** @return array<string, string> */
    private function loadJson(string $path): array
    {
        /** @var mixed $data */
        $data = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        /** @var mixed $alps */
        $alps = $data instanceof stdClass ? ($data->alps ?? null) : null;
        /** @var list<stdClass>|stdClass $descriptors */
        $descriptors = $alps instanceof stdClass ? ($alps->descriptor ?? []) : [];
        $dictionary = [];
        $this->walkJson($descriptors, $dictionary);

        return $dictionary;
    }

    /**
     * @param list<stdClass>|stdClass $descriptors
     * @param array<string, string>   $dictionary
     */
    private function walkJson(array|stdClass $descriptors, array &$dictionary): void
    {
        $list = is_array($descriptors) ? $descriptors : [$descriptors];
        foreach ($list as $descriptor) {
            if (! isset($descriptor->id)) {
                continue;
            }

            $description = $this->extractJsonDescription($descriptor);
            if ($description !== null) {
                $dictionary[(string) $descriptor->id] = $description;
            }

            if (! isset($descriptor->descriptor)) {
                continue;
            }

            /** @var list<stdClass>|stdClass $nested */
            $nested = $descriptor->descriptor;
            $this->walkJson($nested, $dictionary);
        }
    }

    private function extractJsonDescription(stdClass $descriptor): string|null
    {
        if (isset($descriptor->title) && $descriptor->title !== '') {
            return (string) $descriptor->title;
        }

        /** @var mixed $doc */
        $doc = $descriptor->doc ?? null;
        if (is_string($doc) && $doc !== '') {
            return $doc;
        }

        if ($doc instanceof stdClass && isset($doc->value) && $doc->value !== '') {
            return (string) $doc->value;
        }

        return null;
    }

    /** @return array<string, string> */
    private function loadXml(string $path): array
    {
        $xml = simplexml_load_string((string) file_get_contents($path));
        if ($xml === false) {
            throw new InvalidArgumentException(sprintf('Failed to parse ALPS XML profile: %s', $path));
        }

        $dictionary = [];
        /** @psalm-suppress PossiblyNullArgument */
        $this->walkXml($xml->descriptor, $dictionary);

        return $dictionary;
    }

    /** @param array<string, string> $dictionary */
    private function walkXml(SimpleXMLElement $descriptors, array &$dictionary): void
    {
        foreach ($descriptors as $descriptor) {
            $id = (string) ($descriptor['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $description = $this->extractXmlDescription($descriptor);
            if ($description !== null) {
                $dictionary[$id] = $description;
            }

            if (! isset($descriptor->descriptor)) {
                continue;
            }

            $this->walkXml($descriptor->descriptor, $dictionary);
        }
    }

    private function extractXmlDescription(SimpleXMLElement $descriptor): string|null
    {
        $title = (string) ($descriptor['title'] ?? '');
        if ($title !== '') {
            return $title;
        }

        if (isset($descriptor->doc)) {
            $doc = trim((string) $descriptor->doc);
            if ($doc !== '') {
                return $doc;
            }
        }

        return null;
    }
}
