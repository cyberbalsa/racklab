<?php

declare(strict_types=1);

namespace App\Docs\Refs;

/**
 * Extracts `[[kind:id]]` references from a Markdown source string.
 *
 * The parser is intentionally lenient about what surrounds a ref —
 * matches inside code fences or inline-code spans are returned by
 * `extractAll()` along with the rest. Callers that need to honor
 * Markdown context (e.g. the renderer's CommonMark inline parser)
 * apply their own scoping; this parser is the pure-string utility
 * used by the audit-emission path, cross-link index, etc.
 */
final readonly class RackLabRefParser
{
    /**
     * Matches `[[kind:id]]` non-greedily; same character classes as
     * `RackLabRef`'s structural validators.
     */
    public const string PATTERN = '/\[\[([a-z][a-z0-9_]{1,31}):([A-Za-z0-9_\-]{1,64})]]/';

    /**
     * @return list<RackLabRef>
     */
    public function extractAll(string $source): array
    {
        if (preg_match_all(self::PATTERN, $source, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $refs = [];

        foreach ($matches as $match) {
            $refs[] = new RackLabRef(kind: $match[1], id: $match[2]);
        }

        return $refs;
    }

    /**
     * Returns refs deduplicated by `kind`/`id` pair, preserving the
     * first-seen order. Useful for emitting one resolution audit row
     * per unique target instead of one per source occurrence.
     *
     * @return list<RackLabRef>
     */
    public function extractUnique(string $source): array
    {
        $seen = [];
        $unique = [];

        foreach ($this->extractAll($source) as $ref) {
            $key = $ref->kind.':'.$ref->id;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $ref;
        }

        return $unique;
    }
}
