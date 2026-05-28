<?php

declare(strict_types=1);

use App\Filament\Resources\VpnPublicIpPoolResource;
use App\Filament\Resources\VpnPublicIpPoolResource\Pages\CreateVpnPublicIpPool;
use App\Filament\Resources\VpnPublicIpPoolResource\Pages\EditVpnPublicIpPool;
use App\Filament\Resources\VpnPublicIpPoolResource\Pages\ListVpnPublicIpPools;
use App\Models\VpnPublicIpPool;

it('registers a Filament resource with index/create/edit pages for VPN public IP pools', function (): void {
    expect(VpnPublicIpPoolResource::getModel())->toBe(VpnPublicIpPool::class);

    $pages = VpnPublicIpPoolResource::getPages();
    expect(array_keys($pages))->toBe(['index', 'create', 'edit']);
});

it('declares the documented navigation group and labels', function (): void {
    expect(VpnPublicIpPoolResource::getNavigationGroup())->toBe('Networking')
        ->and(VpnPublicIpPoolResource::getNavigationLabel())->toBe('VPN Public IP Pools')
        ->and(VpnPublicIpPoolResource::getPluralModelLabel())->toBe('VPN public IP pools');
});

it('exposes the tenant ownership relationship so Filament scopes records', function (): void {
    expect(VpnPublicIpPoolResource::getTenantOwnershipRelationshipName())->toBe('tenant');
});

it('points each route at its concrete page class', function (): void {
    $pages = VpnPublicIpPoolResource::getPages();
    expect($pages['index']->getPage())->toBe(ListVpnPublicIpPools::class)
        ->and($pages['create']->getPage())->toBe(CreateVpnPublicIpPool::class)
        ->and($pages['edit']->getPage())->toBe(EditVpnPublicIpPool::class);
});
