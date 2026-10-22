<?php

namespace App\Filament\Resources\Books\RelationManagers;

use App\Filament\Actions\PlaidActions;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Resources\Accounts\Tables\AccountsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'accounts';

    protected static ?string $recordTitleAttribute = 'name';

    /** @var array<string, string> */
    protected $listeners = [PlaidActions::SYNCED_EVENT => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return AccountForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AccountsTable::configure($table, withinBook: true);
    }
}
