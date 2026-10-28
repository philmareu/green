<?php

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\RecurringsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Resources\Accounts\Schemas\AccountInfolist;
use App\Filament\Resources\Accounts\Tables\AccountsTable;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(auth()->user());
    }

    /**
     * Include the mask so two accounts with the same name can be told apart.
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->display_name ?? static::getModelLabel();
    }

    public static function canCreate(): bool
    {
        // Accounts come from Plaid; use "Link a bank" instead.
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return AccountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
            RecurringsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'view' => ViewAccount::route('/{record}'),
            'edit' => EditAccount::route('/{record}/edit'),
        ];
    }
}
