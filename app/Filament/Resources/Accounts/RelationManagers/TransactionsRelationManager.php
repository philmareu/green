<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Actions\PlaidActions;
use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    /** @var array<string, string> */
    protected $listeners = [PlaidActions::SYNCED_EVENT => '$refresh'];

    public function table(Table $table): Table
    {
        return TransactionsTable::configure($table, withinAccount: true);
    }
}
