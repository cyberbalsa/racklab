<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Identity\PersonalProjectProvisioner;
use App\Models\User;
use App\Tenancy\DefaultTenantContextResolver;
use Illuminate\Auth\Events\Login;

final readonly class EnsurePersonalProjectForLogin
{
    public function __construct(
        private DefaultTenantContextResolver $tenantContext,
        private PersonalProjectProvisioner $personalProjects,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $context = $this->tenantContext->resolve($event->user);

        $this->personalProjects->ensureFor($event->user, $context);
    }
}
