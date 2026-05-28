<?php

declare(strict_types=1);

use App\Models\VpnClientProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects profiles whose expires_at is in the past even before the row is flipped to expired', function (): void {
    CarbonImmutable::setTestNow('2026-05-28T12:00:00Z');

    $profile = (new VpnClientProfile)->forceFill([
        'state' => VpnClientProfile::STATE_ACTIVE,
        'revoked_at' => null,
        'expires_at' => now()->subSeconds(1),
    ]);

    expect($profile->isActive())->toBeFalse();

    CarbonImmutable::setTestNow();
});

it('accepts active profiles with a future expires_at or no expires_at', function (): void {
    CarbonImmutable::setTestNow('2026-05-28T12:00:00Z');

    $live = (new VpnClientProfile)->forceFill([
        'state' => VpnClientProfile::STATE_ACTIVE,
        'revoked_at' => null,
        'expires_at' => now()->addHour(),
    ]);
    expect($live->isActive())->toBeTrue();

    $perpetual = (new VpnClientProfile)->forceFill([
        'state' => VpnClientProfile::STATE_ACTIVE,
        'revoked_at' => null,
        'expires_at' => null,
    ]);
    expect($perpetual->isActive())->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('rejects revoked profiles regardless of expires_at', function (): void {
    $revoked = (new VpnClientProfile)->forceFill([
        'state' => VpnClientProfile::STATE_REVOKED,
        'revoked_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    expect($revoked->isActive())->toBeFalse();
});
