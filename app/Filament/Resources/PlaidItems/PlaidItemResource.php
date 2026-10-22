<?php

namespace App\Filament\Resources\PlaidItems;

use App\Filament\Resources\PlaidItems\Pages\ListPlaidItems;
use App\Filament\Resources\PlaidItems\Tables\PlaidItemsTable;
use App\Models\PlaidItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaidItemResource extends Resource
{
    protected static ?string $model = PlaidItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'bank connection';

    protected static ?string $slug = 'bank-connections';

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $broken = static::getEloquentQuery()->where('status', '!=', 'active')->count();

        return $broken > 0 ? (string) $broken : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Connections that need attention';
    }

    public static function table(Table $table): Table
    {
        return PlaidItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaidItems::route('/'),
        ];
    }
}
