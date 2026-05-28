<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderDriftResource\Pages;
use App\Models\ProviderDrift;
use App\Models\User;
use App\Networking\ProviderDriftResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;
use UnitEnum;

final class ProviderDriftResource extends Resource
{
    protected static ?string $model = ProviderDrift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Provider Drift';

    protected static ?string $modelLabel = 'provider drift';

    protected static ?string $pluralModelLabel = 'provider drift';

    protected static ?string $recordTitleAttribute = 'resource_label';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('resource_label')
            ->columns([
                TextColumn::make('resource_label')
                    ->label('Resource')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('resource_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('provider')
                    ->badge()
                    ->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->sortable(),
                TextColumn::make('detected_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('resolution')
                    ->badge()
                    ->placeholder('Open'),
            ])
            ->recordActions([
                Action::make('repair')
                    ->label('Repair')
                    ->requiresConfirmation()
                    ->visible(fn (ProviderDrift $record): bool => $record->state === 'detected')
                    ->action(function (ProviderDrift $record): void {
                        $user = auth()->user();

                        app(ProviderDriftResolver::class)->repair($record, $user instanceof User ? $user : null);
                    }),
                Action::make('adopt')
                    ->label('Adopt')
                    ->requiresConfirmation()
                    ->visible(fn (ProviderDrift $record): bool => $record->state === 'detected')
                    ->action(function (ProviderDrift $record): void {
                        $user = auth()->user();

                        app(ProviderDriftResolver::class)->adopt($record, $user instanceof User ? $user : null);
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderDrifts::route('/'),
        ];
    }
}
