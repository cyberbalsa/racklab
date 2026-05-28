<?php

declare(strict_types=1);

use App\Docs\Refs\Resolving\CacheDedupRefResolveAuditSampler;
use App\Docs\Refs\Resolving\RefResolutionStatus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function sampler(float $rate = 1.0, int $window = 300): CacheDedupRefResolveAuditSampler
{
    return new CacheDedupRefResolveAuditSampler(new Repository(new ArrayStore), $rate, $window);
}

it('records a denied outcome only once per actor/ref within the window', function (): void {
    $sampler = sampler();

    expect($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Redacted))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Redacted))->toBeFalse();
});

it('records not-found and unsupported outcomes only once per window', function (): void {
    $sampler = sampler();

    expect($sampler->shouldRecord('user-1', 'deployment', 'gone', RefResolutionStatus::NotFound))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'deployment', 'gone', RefResolutionStatus::NotFound))->toBeFalse()
        ->and($sampler->shouldRecord('user-1', 'widget', 'x', RefResolutionStatus::Unsupported))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'widget', 'x', RefResolutionStatus::Unsupported))->toBeFalse();
});

it('keys the dedup window by actor, kind, id, and status independently', function (): void {
    $sampler = sampler();

    expect($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Redacted))->toBeTrue()
        ->and($sampler->shouldRecord('user-2', 'deployment', 'dep-1', RefResolutionStatus::Redacted))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'project', 'dep-1', RefResolutionStatus::Redacted))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'deployment', 'dep-2', RefResolutionStatus::Redacted))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::NotFound))->toBeTrue();
});

it('never records a successful resolution when the sample rate is zero', function (): void {
    $sampler = sampler(rate: 0.0);

    expect($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Resolved))->toBeFalse()
        ->and($sampler->shouldRecord('user-1', 'deployment', 'dep-2', RefResolutionStatus::Resolved))->toBeFalse();
});

it('records a sampled-in success once per window at full sample rate', function (): void {
    $sampler = sampler(rate: 1.0);

    expect($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Resolved))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Resolved))->toBeFalse();
});

it('disables dedup when the window is zero', function (): void {
    $sampler = sampler(rate: 1.0, window: 0);

    expect($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Redacted))->toBeTrue()
        ->and($sampler->shouldRecord('user-1', 'deployment', 'dep-1', RefResolutionStatus::Redacted))->toBeTrue();
});

it('rejects an out-of-range sample rate', function (): void {
    expect(fn (): CacheDedupRefResolveAuditSampler => sampler(rate: 1.5))
        ->toThrow(InvalidArgumentException::class);
});
