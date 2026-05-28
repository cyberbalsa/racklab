<?php

declare(strict_types=1);

namespace App\Filament\Resources\VpnPublicIpPoolResource\Pages;

use App\Filament\Resources\VpnPublicIpPoolResource;
use Filament\Resources\Pages\ListRecords;

final class ListVpnPublicIpPools extends ListRecords
{
    protected static string $resource = VpnPublicIpPoolResource::class;
}
