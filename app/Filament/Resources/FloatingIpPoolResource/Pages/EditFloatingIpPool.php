<?php

declare(strict_types=1);

namespace App\Filament\Resources\FloatingIpPoolResource\Pages;

use App\Filament\Resources\FloatingIpPoolResource;
use Filament\Resources\Pages\EditRecord;

final class EditFloatingIpPool extends EditRecord
{
    protected static string $resource = FloatingIpPoolResource::class;
}
