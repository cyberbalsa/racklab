<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Sentinel identity for RackLab platform-wide resources (Horizon dashboard,
 * future admin endpoints, anything that isn't tenant-scoped).
 *
 * Role bindings that target the platform must use
 * `(scope_type=Global, resource_type=PlatformResource::RESOURCE_TYPE, resource_id=PlatformResource::RACKLAB_ID)`.
 * `AccessResolver::permittedPlatform()` is the only sanctioned entry point.
 *
 * This is not a TenantScopedResource — the platform has no meaningful tenant.
 * See docs/superpowers/specs/2026-05-28-horizon-and-supply-chain-design.md §3.
 */
final class PlatformResource
{
    public const string RESOURCE_TYPE = 'platform';

    public const string RACKLAB_ID = 'racklab';
}
