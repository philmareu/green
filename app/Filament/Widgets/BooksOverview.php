<?php

namespace App\Filament\Widgets;

use App\Enums\PlaidItemStatus;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\PlaidItems\PlaidItemResource;
use App\Models\Book;
use App\Models\PlaidItem;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * One card per Book: the cash you can actually spend right now (available
 * balance, which already accounts for pending activity), plus what's owed.
 */
class BooksOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Books';

    protected function getStats(): array
    {
        $user = auth()->user();

        $books = Book::query()
            ->ownedBy($user)
            ->with('accounts')
            ->withCount(['transactions as pending_count' => fn ($query) => $query->where('pending', true)])
            ->orderBy('name')
            ->get();

        if ($books->isEmpty()) {
            return [
                Stat::make('No Books yet', 'Get started')
                    ->description('Create a Book for each business or household')
                    ->descriptionIcon(Heroicon::OutlinedPlus)
                    ->url(BookResource::getUrl('create')),
            ];
        }

        $stats = $books->map(function (Book $book): Stat {
            $currency = $book->currency();
            $details = [];

            if (($owed = $book->creditOwed()) > 0) {
                $details[] = Number::currency($owed, $currency).' credit owed';
            }

            $details[] = $book->pending_count === 1 ? '1 pending' : "{$book->pending_count} pending";

            return Stat::make($book->name, Number::currency($book->cashAvailable(), $currency))
                ->description('Available · '.implode(' · ', $details))
                ->descriptionIcon($book->kind->getIcon())
                ->color($book->kind->getColor())
                ->url(BookResource::getUrl('view', ['record' => $book]));
        });

        $broken = PlaidItem::query()->ownedBy($user)->where('status', '!=', PlaidItemStatus::Active)->count();

        if ($broken > 0) {
            $stats->push(
                Stat::make('Bank connections', $broken === 1 ? '1 needs attention' : "{$broken} need attention")
                    ->description('Reconnect to resume syncing')
                    ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->url(PlaidItemResource::getUrl()),
            );
        }

        return $stats->all();
    }
}
