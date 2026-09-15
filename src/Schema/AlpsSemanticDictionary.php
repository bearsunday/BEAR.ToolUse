<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use ArrayObject;
use InvalidArgumentException;
use JsonException;
use SimpleXMLElement;
use stdClass;

use function array_map;
use function file_get_contents;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
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
 * Full descriptors, including transitions, are available through getDescriptor().
 *
 * Behavior matches koriym/app-state-diagram for the subset we need:
 *  - Only `type === 'semantic'` descriptors are included in the array mapping.
 *  - Same-profile `href="#id"` references resolve to the referenced description.
 *  - Cross-file href references are not supported.
 *
 * @phpstan-type Entry array{id: string, type: string, description: string|null, href: string|null}
 * @extends ArrayObject<string, string>
 */
final class AlpsSemanticDictionary extends ArrayObject
{
    private AlpsDescriptorIndex $descriptorIndex;

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

        $this->descriptorIndex = new AlpsDescriptorIndex($entries);

        parent::__construct($this->descriptorIndex->semanticDictionary());
    }

    public function get(string $key): string|null
    {
        return $this[$key] ?? null;
    }

    /** @return Entry|null */
    public function getDescriptor(string $id): array|null
    {
        return $this->descriptorIndex->get($id);
    }

    /** @return list<Entry> */
    private function parseJson(string $path): array
    {
        $contents = $this->readProfile($path);

        try {
            /** @var mixed $data */
            $data = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                sprintf('Failed to parse ALPS JSON profile %s: %s', $path, $e->getMessage()),
                0,
                $e,
            );
        }

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
        $contents = $this->readProfile($path);

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents);
            if ($xml === false) {
                $errors = array_map(
                    static fn (object $error): string => trim($error->message),
                    libxml_get_errors(),
                );
                libxml_clear_errors();

                throw new InvalidArgumentException(sprintf(
                    'Failed to parse ALPS XML profile %s: %s',
                    $path,
                    implode('; ', $errors),
                ));
            }
        } finally {
            libxml_use_internal_errors($previous);
        }

        $entries = [];
        /** @psalm-suppress PossiblyNullArgument */
        $this->walkXml($xml->descriptor, $entries);

        return $entries;
    }

    private function readProfile(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read ALPS profile: %s', $path));
        }

        return $contents;
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
