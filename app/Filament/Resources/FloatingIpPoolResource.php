<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FloatingIpPoolResource\Pages;
use App\Models\FloatingIpPool;
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

final class FloatingIpPoolResource extends Resource
{
    protected static ?string $model = FloatingIpPool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Networking';

    protected static ?string $navigationLabel = 'Floating IP Pools';

    protected static ?string $modelLabel = 'floating IP pool';

    protected static ?string $pluralModelLabel = 'floating IP pools';

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
            TextInput::make('cidr')
                ->required()
                ->maxLength(64),
            TextInput::make('ip_version')
                ->required()
                ->numeric()
                ->minValue(4)
                ->maxValue(4)
                ->default(4),
            TextInput::make('provider')
                ->required()
                ->maxLength(80),
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
                TextColumn::make('cidr')
                    ->searchable(),
                TextColumn::make('provider')
                    ->sortable(),
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
            'index' => Pages\ListFloatingIpPools::route('/'),
            'create' => Pages\CreateFloatingIpPool::route('/create'),
            'edit' => Pages\EditFloatingIpPool::route('/{record}/edit'),
        ];
    }
}
