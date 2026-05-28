<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SubnetPoolResource\Pages;
use App\Models\SubnetPool;
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

final class SubnetPoolResource extends Resource
{
    protected static ?string $model = SubnetPool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

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
            TextInput::make('cidr')
                ->required()
                ->maxLength(64),
            TextInput::make('ip_version')
                ->required()
                ->numeric()
                ->minValue(4)
                ->maxValue(4)
                ->default(4),
            TextInput::make('default_prefix_length')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(32),
            TextInput::make('min_prefix_length')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(32),
            TextInput::make('max_prefix_length')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(32),
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
                TextColumn::make('default_prefix_length')
                    ->label('Default prefix')
                    ->sortable(),
                TextColumn::make('min_prefix_length')
                    ->label('Min prefix')
                    ->sortable(),
                TextColumn::make('max_prefix_length')
                    ->label('Max prefix')
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
            'index' => Pages\ListSubnetPools::route('/'),
            'create' => Pages\CreateSubnetPool::route('/create'),
            'edit' => Pages\EditSubnetPool::route('/{record}/edit'),
        ];
    }
}
