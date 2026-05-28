<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuotaLimitResource\Pages;
use App\Models\QuotaLimit;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;
use UnitEnum;

final class QuotaLimitResource extends Resource
{
    protected static ?string $model = QuotaLimit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $recordTitleAttribute = 'dimension';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('scope_type')
                ->required()
                ->options([
                    'tenant' => 'Tenant',
                    'project' => 'Project',
                    'course' => 'Course',
                    'user' => 'User',
                ]),
            TextInput::make('scope_id')
                ->required()
                ->maxLength(64),
            Select::make('dimension')
                ->required()
                ->options([
                    'vcpu' => 'vCPU',
                    'memory_mb' => 'Memory MB',
                    'storage_gb' => 'Storage GB',
                    'concurrent_deployments' => 'Concurrent deployments',
                    'concurrent_leased_deployments' => 'Concurrent leased deployments',
                    'lease_duration_minutes' => 'Lease duration minutes',
                    'provider_direct_nics' => 'Provider-direct NICs',
                    'private_networks' => 'Private networks',
                    'routers' => 'Routers',
                    'floating_ips' => 'Floating IPs',
                    'vpn_endpoint_ports' => 'VPN endpoint ports',
                    'vpn_client_profiles' => 'VPN client profiles',
                ]),
            TextInput::make('limit_value')
                ->required()
                ->numeric()
                ->minValue(0),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('dimension')
            ->columns([
                TextColumn::make('scope_type')
                    ->sortable(),
                TextColumn::make('scope_id')
                    ->searchable(),
                TextColumn::make('dimension')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('limit_value')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotaLimits::route('/'),
            'create' => Pages\CreateQuotaLimit::route('/create'),
            'edit' => Pages\EditQuotaLimit::route('/{record}/edit'),
        ];
    }
}
