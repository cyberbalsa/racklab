<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\VpnPublicIpPoolResource\Pages;
use App\Models\VpnPublicIpPool;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;
use UnitEnum;

final class VpnPublicIpPoolResource extends Resource
{
    protected static ?string $model = VpnPublicIpPool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Networking';

    protected static ?string $navigationLabel = 'VPN Public IP Pools';

    protected static ?string $modelLabel = 'VPN public IP pool';

    protected static ?string $pluralModelLabel = 'VPN public IP pools';

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
                ->default('openvpn')
                ->maxLength(80),
            TextInput::make('cidr')
                ->required()
                ->maxLength(64),
            TextInput::make('port_range_min')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(65535)
                ->default(20000),
            TextInput::make('port_range_max')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(65535)
                ->default(29999),
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
                TextColumn::make('port_range_min')
                    ->label('Port min')
                    ->sortable(),
                TextColumn::make('port_range_max')
                    ->label('Port max')
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
            'index' => Pages\ListVpnPublicIpPools::route('/'),
            'create' => Pages\CreateVpnPublicIpPool::route('/create'),
            'edit' => Pages\EditVpnPublicIpPool::route('/{record}/edit'),
        ];
    }
}
