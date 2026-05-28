<?php

declare(strict_types=1);

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel(
    'tenant.{tenantId}.deployment.{deploymentId}',
    function (User $user, string $tenantId, string $deploymentId): bool {
        /** @var Deployment|null $deployment */
        $deployment = Deployment::query()->whereKey($deploymentId)->first();

        if (! $deployment instanceof Deployment || $deployment->tenant_id !== $tenantId) {
            return false;
        }

        return app(AccessResolver::class)
            ->permitted(
                new ActorIdentity((string) $user->id),
                new Permission('deployment.read'),
                $deployment,
                new TenantContext($tenantId),
            )
            ->allowed;
    },
);
