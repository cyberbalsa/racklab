<?php

declare(strict_types=1);

namespace App\Domain\Console;

use ValueError;

enum ConsoleKind: string
{
    case Vnc = 'vnc';
    case Terminal = 'terminal';

    public static function fromName(string $name): self
    {
        $normalized = strtolower(trim($name));

        if ($normalized === '') {
            throw new ValueError('Console kind name cannot be empty.');
        }

        return self::from($normalized);
    }

    /**
     * @return list<string>
     */
    public static function supportedValues(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
