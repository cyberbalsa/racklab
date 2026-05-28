<?php

declare(strict_types=1);

namespace App\Filament\Resources\NetworkOfferingResource\Pages;

use App\Filament\Resources\NetworkOfferingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateNetworkOffering extends CreateRecord
{
    protected static string $resource = NetworkOfferingResource::class;
}
