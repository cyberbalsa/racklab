<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NetworkOfferingResource\Pages;
use App\Models\NetworkOffering;
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

final class NetworkOfferingResource extends Resource
{
    protected static ?string $model = NetworkOffering::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWifi;

    protected static string|UnitEnum|null $navigationGroup = 'Networking';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider_network_id')
                ->relationship('providerNetwork', 'name')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->required()
                ->maxLength(160),
            TextInput::make('slug')
                ->required()
                ->maxLength(160),
            Select::make('offering_type')
                ->required()
                ->options([
                    'private-isolated' => 'Private isolated',
                    'private-nat' => 'Private NAT',
                    'double-nat' => 'Double NAT',
                    'provider-direct' => 'Provider direct',
                    'template-defined' => 'Template defined',
                ]),
            Select::make('reachability')
                ->required()
                ->options([
                    'routable_from_management' => 'Routable from management',
                    'nat_from_management' => 'NAT from management',
                    'isolated_no_ingress' => 'Isolated, no ingress',
                ]),
            TextInput::make('metadata.nat.host')
                ->label('NAT host')
                ->maxLength(255),
            TextInput::make('metadata.nat.port')
                ->label('NAT port')
                ->numeric()
                ->minValue(1)
                ->maxValue(65535),
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
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('offering_type')
                    ->sortable(),
                TextColumn::make('reachability')
                    ->sortable(),
                TextColumn::make('providerNetwork.provider')
                    ->label('Provider'),
                TextColumn::make('providerNetwork.external_id')
                    ->label('Provider network')
                    ->searchable(),
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
            'index' => Pages\ListNetworkOfferings::route('/'),
            'create' => Pages\CreateNetworkOffering::route('/create'),
            'edit' => Pages\EditNetworkOffering::route('/{record}/edit'),
        ];
    }
}
