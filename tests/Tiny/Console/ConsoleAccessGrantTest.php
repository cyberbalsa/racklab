<?php

declare(strict_types=1);

use App\Domain\Console\ConsoleAccessGrant;
use App\Domain\Console\ConsoleKind;
use Carbon\CarbonImmutable;

it('exposes typed properties for the wire payload', function (): void {
    $expiresAt = CarbonImmutable::create(2026, 5, 28, 12, 0, 0);

    $grant = new ConsoleAccessGrant(
        grantId: '01J6CONSOLEGRANT00000000000',
        jti: '01J6CONSOLEJTI000000000000',
        tenantId: '01J6CONSOLETENANT0000000000',
        deploymentId: '01J6CONSOLEDEPLOY00000000000',
        consoleKind: ConsoleKind::Vnc,
        expiresAt: $expiresAt,
    );

    expect($grant->grantId)->toBe('01J6CONSOLEGRANT00000000000')
        ->and($grant->jti)->toBe('01J6CONSOLEJTI000000000000')
        ->and($grant->tenantId)->toBe('01J6CONSOLETENANT0000000000')
        ->and($grant->deploymentId)->toBe('01J6CONSOLEDEPLOY00000000000')
        ->and($grant->consoleKind)->toBe(ConsoleKind::Vnc)
        ->and($grant->expiresAt->getTimestamp())->toBe($expiresAt->getTimestamp());
});

it('treats grants as expired when expiry is in the past', function (): void {
    $grant = new ConsoleAccessGrant(
        grantId: 'g',
        jti: 'j',
        tenantId: 't',
        deploymentId: 'd',
        consoleKind: ConsoleKind::Terminal,
        expiresAt: CarbonImmutable::now()->subSeconds(1),
    );

    expect($grant->isExpired())->toBeTrue();
});

it('treats grants as live until the expiry timestamp', function (): void {
    $grant = new ConsoleAccessGrant(
        grantId: 'g',
        jti: 'j',
        tenantId: 't',
        deploymentId: 'd',
        consoleKind: ConsoleKind::Terminal,
        expiresAt: CarbonImmutable::now()->addSeconds(60),
    );

    expect($grant->isExpired())->toBeFalse();
});
