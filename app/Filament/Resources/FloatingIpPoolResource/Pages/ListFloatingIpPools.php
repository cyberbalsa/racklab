<?php

declare(strict_types=1);

namespace App\Filament\Resources\FloatingIpPoolResource\Pages;

use App\Filament\Resources\FloatingIpPoolResource;
use Filament\Resources\Pages\ListRecords;

final class ListFloatingIpPools extends ListRecords
{
    protected static string $resource = FloatingIpPoolResource::class;
}
