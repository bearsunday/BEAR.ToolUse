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
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;

/**
 * ALPS semantic dictionary for description enrichment
 *
 * Parses an ALPS profile (JSON or XML) and exposes an
 * `id` => description mapping for semantic descriptors.
 *
 * Behavior matches koriym/app-state-diagram for the subset we need:
 *  - Only `type === 'semantic'` descriptors are included (transitions excluded).
 *  - Same-profile `href="#id"` references resolve to the referenced description.
 *  - Cross-file href references are not supported.
 *
 * @phpstan-type Entry array{id: string, type: string, description: string|null, href: string|null}
 * @extends ArrayObject<string, string>
 */
final class AlpsSemanticDictionary extends ArrayObject
{
    /** Maximum href chain depth (e.g. A -> B -> C). Guards against cycles. */
    private const HREF_RESOLUTION_DEPTH = 10;

    public function __construct(string $profilePath)
    {
        $extension = strtolower(pathinfo($profilePath, PATHINFO_EXTENSION));
        $entries = match ($extension) {
            'json' => $this->parseJson($profilePath),
            'xml' => $this->parseXml($profilePath),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported ALPS profile format: .%s', $extension),
            ),
        };

        parent::__construct($this->buildDictionary($entries));
    }

    public function get(string $key): string|null
    {
        return $this[$key] ?? null;
    }

    /**
     * @param list<Entry> $entries
     *
     * @return array<string, string>
     */
    private function buildDictionary(array $entries): array
    {
        $semanticEntries = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'semantic') {
                continue;
            }

            $semanticEntries[] = $entry;
        }

        $dictionary = [];
        foreach ($semanticEntries as $entry) {
            if ($entry['description'] === null) {
                continue;
            }

            $dictionary[$entry['id']] = $entry['description'];
        }

        for ($depth = 0; $depth < self::HREF_RESOLUTION_DEPTH; $depth++) {
            if (! $this->resolveHrefPass($semanticEntries, $dictionary)) {
                break;
            }
        }

        return $dictionary;
    }

    /**
     * @param list<Entry>           $semanticEntries
     * @param array<string, string> $dictionary
     */
    private function resolveHrefPass(array $semanticEntries, array &$dictionary): bool
    {
        $changed = false;
        foreach ($semanticEntries as $entry) {
            if (isset($dictionary[$entry['id']])) {
                continue;
            }

            $href = $entry['href'];
            if ($href === null) {
                continue;
            }

            if (! str_starts_with($href, '#')) {
                continue;
            }

            $referencedId = substr($href, 1);
            if (! isset($dictionary[$referencedId])) {
                continue;
            }

            $dictionary[$entry['id']] = $dictionary[$referencedId];
            $changed = true;
        }

        return $changed;
    }

    /** @return list<Entry> */
    private function parseJson(string $path): array
    {
        /** @var mixed $data */
        $data = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        /** @var mixed $alps */
        $alps = $data instanceof stdClass ? ($data->alps ?? null) : null;
        /** @var list<stdClass>|stdClass $descriptors */
        $descriptors = $alps instanceof stdClass ? ($alps->descriptor ?? []) : [];
        $entries = [];
        $this->walkJson($descriptors, $entries);

        return $entries;
    }

    /**
     * @param list<stdClass>|stdClass $descriptors
     * @param list<Entry>             $entries
     */
    private function walkJson(array|stdClass $descriptors, array &$entries): void
    {
        $list = is_array($descriptors) ? $descriptors : [$descriptors];
        foreach ($list as $descriptor) {
            if (isset($descriptor->id)) {
                $entries[] = [
                    'id' => (string) $descriptor->id,
                    'type' => isset($descriptor->type) ? (string) $descriptor->type : 'semantic',
                    'description' => $this->extractJsonDescription($descriptor),
                    'href' => isset($descriptor->href) ? (string) $descriptor->href : null,
                ];
            }

            if (! isset($descriptor->descriptor)) {
                continue;
            }

            /** @var list<stdClass>|stdClass $nested */
            $nested = $descriptor->descriptor;
            $this->walkJson($nested, $entries);
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

    /** @return list<Entry> */
    private function parseXml(string $path): array
    {
        $xml = simplexml_load_string((string) file_get_contents($path));
        if ($xml === false) {
            throw new InvalidArgumentException(sprintf('Failed to parse ALPS XML profile: %s', $path));
        }

        $entries = [];
        /** @psalm-suppress PossiblyNullArgument */
        $this->walkXml($xml->descriptor, $entries);

        return $entries;
    }

    /** @param list<Entry> $entries */
    private function walkXml(SimpleXMLElement $descriptors, array &$entries): void
    {
        foreach ($descriptors as $descriptor) {
            $id = (string) ($descriptor['id'] ?? '');
            if ($id !== '') {
                $entries[] = [
                    'id' => $id,
                    'type' => (string) ($descriptor['type'] ?? 'semantic'),
                    'description' => $this->extractXmlDescription($descriptor),
                    'href' => isset($descriptor['href']) ? (string) $descriptor['href'] : null,
                ];
            }

            if (! isset($descriptor->descriptor)) {
                continue;
            }

            $this->walkXml($descriptor->descriptor, $entries);
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
