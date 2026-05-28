<?php

declare(strict_types=1);

use App\Docs\Refs\Resolving\RefResolutionStatus;
use App\Docs\Refs\Resolving\ResolvedRef;

it('builds a resolved ref carrying label, url, and detail', function (): void {
    $ref = ResolvedRef::resolved('deployment', 'abc-123', 'Lab VM', '/deployments/abc-123', 'running');

    expect($ref->status)->toBe(RefResolutionStatus::Resolved)
        ->and($ref->kind)->toBe('deployment')
        ->and($ref->id)->toBe('abc-123')
        ->and($ref->label)->toBe('Lab VM')
        ->and($ref->url)->toBe('/deployments/abc-123')
        ->and($ref->detail)->toBe('running');
});

it('exposes rbac_visible only for resolved refs in the array shape', function (): void {
    expect(ResolvedRef::resolved('deployment', 'abc', 'L', '/u', 'running')->toArray())
        ->toMatchArray([
            'kind' => 'deployment',
            'id' => 'abc',
            'status' => 'resolved',
            'label' => 'L',
            'url' => '/u',
            'detail' => 'running',
            'rbac_visible' => true,
        ]);

    expect(ResolvedRef::redacted('deployment', 'abc')->toArray())
        ->toMatchArray([
            'kind' => 'deployment',
            'id' => 'abc',
            'status' => 'redacted',
            'label' => null,
            'url' => null,
            'detail' => null,
            'rbac_visible' => false,
        ]);
});

it('redacted refs carry no label, url, or detail', function (): void {
    $ref = ResolvedRef::redacted('network', 'net-1');

    expect($ref->status)->toBe(RefResolutionStatus::Redacted)
        ->and($ref->label)->toBeNull()
        ->and($ref->url)->toBeNull()
        ->and($ref->detail)->toBeNull();
});

it('not-found refs report the not_found status with no content', function (): void {
    $ref = ResolvedRef::notFound('project', 'missing');

    expect($ref->status)->toBe(RefResolutionStatus::NotFound)
        ->and($ref->toArray()['rbac_visible'])->toBeFalse()
        ->and($ref->label)->toBeNull();
});

it('unsupported refs report the unsupported status', function (): void {
    $ref = ResolvedRef::unsupported('widget', 'xyz');

    expect($ref->status)->toBe(RefResolutionStatus::Unsupported)
        ->and($ref->toArray()['status'])->toBe('unsupported')
        ->and($ref->toArray()['rbac_visible'])->toBeFalse();
});
