<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use function str_starts_with;
use function substr;

/**
 * @phpstan-type Entry array{id: string, type: string, description: string|null, href: string|null}
 * @psalm-type Entry = array{id: string, type: string, description: string|null, href: string|null}
 */
final class AlpsDescriptorIndex
{
    /** Maximum href chain depth (e.g. A -> B -> C). Guards against cycles. */
    private const HREF_RESOLUTION_DEPTH = 10;

    /** @var array<string, Entry> */
    private array $descriptors;

    /** @param list<Entry> $entries */
    public function __construct(array $entries)
    {
        $this->descriptors = $this->buildDescriptorMap($entries);
    }

    /** @return Entry|null */
    public function get(string $id): array|null
    {
        return $this->descriptors[$id] ?? null;
    }

    /** @return array<string, string> */
    public function semanticDictionary(): array
    {
        $dictionary = [];
        foreach ($this->descriptors as $entry) {
            if ($entry['type'] !== 'semantic' || $entry['description'] === null) {
                continue;
            }

            $dictionary[$entry['id']] = $entry['description'];
        }

        return $dictionary;
    }

    /**
     * @param list<Entry> $entries
     *
     * @return array<string, Entry>
     */
    private function buildDescriptorMap(array $entries): array
    {
        $descriptors = [];
        foreach ($entries as $entry) {
            $descriptors[$entry['id']] = $entry;
        }

        for ($depth = 0; $depth < self::HREF_RESOLUTION_DEPTH; $depth++) {
            if (! $this->resolveHrefPass($descriptors)) {
                break;
            }
        }

        return $descriptors;
    }

    /** @param array<string, Entry> $descriptors */
    private function resolveHrefPass(array &$descriptors): bool
    {
        $changed = false;
        foreach ($descriptors as $id => $entry) {
            if ($entry['description'] !== null) {
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
            if (! isset($descriptors[$referencedId])) {
                continue;
            }

            $referencedDescription = $descriptors[$referencedId]['description'];
            if ($referencedDescription === null) {
                continue;
            }

            $descriptors[$id]['description'] = $referencedDescription;
            $changed = true;
        }

        return $changed;
    }
}
