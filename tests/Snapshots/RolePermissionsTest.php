<?php

declare(strict_types=1);

use App\Domain\Rbac\DefaultRoleCatalog;
use App\Domain\Rbac\Permission;

it('matches the committed default role permission snapshot', function (): void {
    $snapshot = json_decode(
        (string) file_get_contents(__DIR__.'/roles.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(DefaultRoleCatalog::permissionsByRole())->toBe($snapshot);
});

it('answers role to permission lookups from the default catalog', function (): void {
    $catalog = new DefaultRoleCatalog;

    expect($catalog->roleGrants('student', new Permission('deployment.console')))->toBeTrue()
        ->and($catalog->roleGrants('ta', new Permission('catalog.publish')))->toBeFalse()
        ->and($catalog->roleGrants('support', new Permission('audit.read')))->toBeTrue()
        ->and($catalog->roleGrants('unknown', new Permission('project.read')))->toBeFalse();
});
