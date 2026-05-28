<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProviderDriftResource\Pages;

use App\Filament\Resources\ProviderDriftResource;
use Filament\Resources\Pages\ListRecords;

final class ListProviderDrifts extends ListRecords
{
    protected static string $resource = ProviderDriftResource::class;
}
