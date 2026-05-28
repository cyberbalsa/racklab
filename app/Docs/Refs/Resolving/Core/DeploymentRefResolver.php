<?php

declare(strict_types=1);

namespace App\Docs\Refs\Resolving\Core;

use App\Docs\Refs\Resolving\RefResolutionContext;
use App\Docs\Refs\Resolving\RefResolver;
use App\Docs\Refs\Resolving\ResolvedRef;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Models\Deployment;

/**
 * Core resolver for `[[deployment:id]]` cross-links.
 *
 * Lookups are tenant-scoped (the global `TenantScope` applies), so a
 * deployment in another tenant reads as `not_found`. A deployment in the
 * active tenant that the actor lacks `deployment.read` on reads as
 * `redacted` — never leaking its name or live state.
 */
final readonly class DeploymentRefResolver implements RefResolver
{
    public function __construct(private AccessResolver $access) {}

    public function kind(): string
    {
        return 'deployment';
    }

    public function resolve(RefResolutionContext $context, string $id): ResolvedRef
    {
        /** @var Deployment|null $deployment */
        $deployment = Deployment::query()->whereKey($id)->first();

        if (! $deployment instanceof Deployment) {
            return ResolvedRef::notFound($this->kind(), $id);
        }

        $decision = $this->access->permitted(
            $context->actor,
            new Permission('deployment.read'),
            $deployment,
            $context->tenant,
        );

        if (! $decision->allowed) {
            return ResolvedRef::redacted($this->kind(), $id);
        }

        return ResolvedRef::resolved(
            $this->kind(),
            $id,
            $deployment->name,
            '/deployments/'.$id,
            $deployment->state,
        );
    }
}
