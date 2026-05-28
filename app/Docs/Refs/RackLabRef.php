<?php

declare(strict_types=1);

namespace App\Docs\Refs;

use InvalidArgumentException;

/**
 * Value object for an authored RackLab cross-link reference.
 *
 * Source syntax: `[[kind:id]]` — e.g. `[[deployment:abc-123]]`.
 * `kind` and `id` are validated structurally here; semantic
 * resolution (whether the referenced object exists, what its label
 * is, whether the actor can see it) lives in `RefResolver`
 * implementations registered through the `RefResolving` hookspec.
 */
final readonly class RackLabRef
{
    private const string KIND_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    private const string ID_PATTERN = '/^[A-Za-z0-9_\-]{1,64}$/';

    public function __construct(
        public string $kind,
        public string $id,
    ) {
        if (preg_match(self::KIND_PATTERN, $kind) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid RackLab ref kind: %s (must be lowercase letters/digits/underscore, 2-32 chars, leading letter).',
                $kind,
            ));
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid RackLab ref id: %s (must be 1-64 chars of [A-Za-z0-9_-]).',
                $id,
            ));
        }
    }

    public function toSourceSyntax(): string
    {
        return '[['.$this->kind.':'.$this->id.']]';
    }

    public function equals(RackLabRef $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }
}
