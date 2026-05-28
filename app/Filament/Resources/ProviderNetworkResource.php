<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderNetworkResource\Pages;
use App\Models\ProviderNetwork;
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

final class ProviderNetworkResource extends Resource
{
    protected static ?string $model = ProviderNetwork::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Networking';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(160),
            TextInput::make('slug')
                ->required()
                ->maxLength(160),
            TextInput::make('provider')
                ->required()
                ->maxLength(80),
            TextInput::make('provider_cluster')
                ->maxLength(120),
            Select::make('network_type')
                ->required()
                ->options([
                    'bridge' => 'Bridge',
                    'vlan' => 'VLAN',
                    'vnet' => 'VNet',
                    'sdn_zone' => 'SDN zone',
                ]),
            TextInput::make('external_id')
                ->required()
                ->maxLength(160),
            TextInput::make('bridge')
                ->maxLength(120),
            TextInput::make('vlan_tag')
                ->numeric()
                ->minValue(1)
                ->maxValue(4094),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('network_type')
                    ->sortable(),
                TextColumn::make('external_id')
                    ->searchable(),
                TextColumn::make('bridge'),
                TextColumn::make('vlan_tag')
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
            'index' => Pages\ListProviderNetworks::route('/'),
            'create' => Pages\CreateProviderNetwork::route('/create'),
            'edit' => Pages\EditProviderNetwork::route('/{record}/edit'),
        ];
    }
}
