<?php

declare(strict_types=1);

use App\Domain\Rbac\DefaultRoleCatalog;
use App\Domain\Rbac\Permission;

it('grants console_guest exactly the deployment console-view permissions, nothing more', function (): void {
    $catalog = new DefaultRoleCatalog;

    expect($catalog->roleGrants('console_guest', new Permission('deployment.read')))->toBeTrue()
        ->and($catalog->roleGrants('console_guest', new Permission('deployment.console')))->toBeTrue()
        ->and($catalog->roleGrants('console_guest', new Permission('deployment.console.connect')))->toBeTrue()
        // Console guests must NOT be able to manage or destroy the deployment.
        ->and($catalog->roleGrants('console_guest', new Permission('deployment.update')))->toBeFalse()
        ->and($catalog->roleGrants('console_guest', new Permission('deployment.power')))->toBeFalse()
        ->and($catalog->roleGrants('console_guest', new Permission('deployment.delete')))->toBeFalse()
        ->and($catalog->roleGrants('console_guest', new Permission('deployment.create')))->toBeFalse()
        ->and($catalog->roleGrants('console_guest', new Permission('project.read')))->toBeFalse();
});
