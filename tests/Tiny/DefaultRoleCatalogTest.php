<?php

declare(strict_types=1);

use App\Domain\Rbac\DefaultRoleCatalog;
use App\Domain\Rbac\Permission;

it('grants every tenant member catalog.read but no write or cross-resource read permissions', function (): void {
    $catalog = new DefaultRoleCatalog;

    expect($catalog->roleGrants('tenant_member', new Permission('catalog.read')))->toBeTrue()
        ->and($catalog->roleGrants('tenant_member', new Permission('network.read')))->toBeTrue()
        ->and($catalog->roleGrants('tenant_member', new Permission('deployment.read')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('deployment.create')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('catalog.publish')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('network.attach_provider')))->toBeFalse()
        ->and($catalog->roleGrants('tenant_member', new Permission('project.read')))->toBeFalse();
});
