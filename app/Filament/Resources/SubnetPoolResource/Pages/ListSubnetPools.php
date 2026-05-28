<?php

declare(strict_types=1);

namespace App\Filament\Resources\SubnetPoolResource\Pages;

use App\Filament\Resources\SubnetPoolResource;
use Filament\Resources\Pages\ListRecords;

final class ListSubnetPools extends ListRecords
{
    protected static string $resource = SubnetPoolResource::class;
}
