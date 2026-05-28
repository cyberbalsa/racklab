<?php

declare(strict_types=1);

use App\Domain\Console\ConsoleKind;

it('maps vnc and terminal to enum values', function (): void {
    expect(ConsoleKind::Vnc->value)->toBe('vnc')
        ->and(ConsoleKind::Terminal->value)->toBe('terminal');
});

it('parses canonical lowercase strings', function (): void {
    expect(ConsoleKind::fromName('vnc'))->toBe(ConsoleKind::Vnc)
        ->and(ConsoleKind::fromName('terminal'))->toBe(ConsoleKind::Terminal);
});

it('parses with surrounding whitespace and mixed case', function (): void {
    expect(ConsoleKind::fromName(' VNC '))->toBe(ConsoleKind::Vnc)
        ->and(ConsoleKind::fromName('Terminal'))->toBe(ConsoleKind::Terminal);
});

it('rejects unknown console kind names', function (): void {
    ConsoleKind::fromName('spice');
})->throws(ValueError::class, 'spice');

it('rejects empty console kind names', function (): void {
    ConsoleKind::fromName('   ');
})->throws(ValueError::class);

it('returns all supported values for OpenAPI/route enumeration', function (): void {
    expect(ConsoleKind::supportedValues())->toBe(['vnc', 'terminal']);
});
