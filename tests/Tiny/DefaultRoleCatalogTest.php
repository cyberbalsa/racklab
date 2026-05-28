<?php

declare(strict_types=1);

use App\Domain\Rbac\DefaultRoleCatalog;
use App\Domain\Rbac\Permission;

it('grants every tenant member catalog.read but no write or cross-resource read permissions', function (): void {
    $catalog = new DefaultRoleCatalog;

    expect($catalog->roleGrants('tenant_member', new Permission('catalog.read')))->toBeTrue()
        // Offering reads use a dedicated permission so a tenant-wide binding
        // does NOT also grant broad `network.read` (project networks + the
        // [[network:id]] ref resolver), which would leak other projects.
        ->and($catalog->roleGrants('tenant_member', new Permission('network.offering.read')))->toBeTrue()
        ->and($catalog->roleGrants('tenant_member', new Permission('network.read')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('deployment.read')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('deployment.create')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('catalog.publish')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('network.attach_provider')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('project.read')))->toBeFalse();
});
