<?php

namespace App\Filament\Resources\PlaidLogs;

use App\Filament\Resources\PlaidLogs\Pages\ListPlaidLogs;
use App\Filament\Resources\PlaidLogs\Tables\PlaidLogsTable;
use App\Models\PlaidLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only list of the Plaid activity log.
 */
class PlaidLogResource extends Resource
{
    protected static ?string $model = PlaidLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Plaid activity';

    protected static ?string $pluralModelLabel = 'Plaid activity';

    protected static ?string $slug = 'plaid-activity';

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PlaidLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaidLogs::route('/'),
        ];
    }
}
