<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Models\Transaction;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingTransactions extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Pending transactions')
            ->description('Authorized but not yet posted, across every Book.')
            ->query(fn () => Transaction::query()
                ->ownedBy(auth()->user())
                ->onlyPending()
                ->with(['account.book.categorySet', 'account.plaidItem', 'occurrence.recurring'])
                ->latest('date'))
            ->columns(collect(TransactionsTable::columns())
                ->reject(fn ($column) => $column->getName() === 'pending')
                ->all())
            ->paginated([10, 25])
            ->emptyStateHeading('Nothing pending')
            ->emptyStateDescription('Every transaction has posted.');
    }
}
