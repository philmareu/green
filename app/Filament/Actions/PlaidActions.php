<?php

namespace App\Filament\Actions;

use App\Filament\Pages\LinkBank;
use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidException;
use App\Services\Plaid\RefreshLiveBalances;
use App\Services\Plaid\RemovePlaidItem;
use App\Services\Plaid\SyncPlaidItem;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Actions shared by several screens. Each one accepts a Plaid item, an
 * account (acts on its item) or a Book (acts on every item behind it).
 */
class PlaidActions
{
    /**
     * Dispatched after a sync or balance refresh so tables elsewhere on the page re-render.
     */
    public const SYNCED_EVENT = 'plaid-synced';

    public static function sync(): Action
    {
        return Action::make('sync')
            ->label('Sync now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (Model $record, SyncPlaidItem $sync, Component $livewire): void {
                $items = static::itemsFor($record);

                if ($items->isEmpty()) {
                    Notification::make()->title('No linked banks to sync yet.')->warning()->send();

                    return;
                }

                foreach ($items as $item) {
                    $result = $sync->handle($item);

                    Notification::make()
                        ->title(e($item->institution_name ?? 'Bank').($result->successful ? ' synced' : ' sync failed'))
                        ->body(e($result->summary()))
                        ->status($result->successful ? 'success' : 'danger')
                        ->send();
                }

                $record->refresh();
                $livewire->dispatch(static::SYNCED_EVENT);
            });
    }

    /**
     * Fetch real-time balances from the bank. Plaid bills each call in
     * production, so it asks first.
     */
    public static function refreshLiveBalance(): Action
    {
        return Action::make('refreshLiveBalance')
            ->label('Live balance')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->tooltip('Fetch the balance straight from the bank. Plaid bills this per call in production.')
            ->requiresConfirmation()
            ->modalIconColor('warning')
            ->modalHeading('Fetch a live balance?')
            ->modalDescription('Green will ask the bank for this account’s balance right now. In production, Plaid bills every live balance request: about $0.10 each on pay-as-you-go pricing, and your plan may differ. The hourly sync updates balances too, with no per-request charge, though they can lag behind the bank.')
            ->modalSubmitActionLabel('Fetch balance')
            ->action(function (Account $record, RefreshLiveBalances $refresh, Component $livewire): void {
                try {
                    $refresh->handle($record->plaidItem, [$record->plaid_account_id]);
                } catch (PlaidException $e) {
                    Notification::make()->title('Could not refresh balance')->body(e($e->userMessage()))->danger()->send();

                    return;
                }

                $record->refresh();
                $livewire->dispatch(static::SYNCED_EVENT);

                Notification::make()->title('Balance updated')->success()->send();
            });
    }

    public static function reconnect(): Action
    {
        return Action::make('reconnect')
            ->label('Reconnect')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->visible(fn (Model $record): bool => (bool) static::itemsFor($record)->first()?->needsReconnect())
            ->url(fn (Model $record): string => LinkBank::getUrl(['item' => static::itemsFor($record)->first()->getKey()]));
    }

    public static function remove(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (PlaidItem $record): string => "Remove {$record->institution_name}?")
            ->modalDescription('This disconnects the bank at Plaid and deletes its accounts and transactions from Green. You can link it again later.')
            ->modalSubmitActionLabel('Remove connection')
            ->action(function (PlaidItem $record, RemovePlaidItem $remove): void {
                try {
                    $remove->handle($record);
                } catch (PlaidException $e) {
                    Notification::make()->title('Could not remove connection')->body(e($e->userMessage()))->danger()->send();

                    return;
                }

                Notification::make()->title('Bank connection removed')->success()->send();
            });
    }

    /** @return Collection<int, PlaidItem> */
    protected static function itemsFor(Model $record): Collection
    {
        return match (true) {
            $record instanceof PlaidItem => collect([$record]),
            $record instanceof Account => collect([$record->plaidItem]),
            $record instanceof Book => PlaidItem::query()
                ->whereHas('accounts', fn ($query) => $query->whereBelongsTo($record))
                ->get(),
            default => collect(),
        };
    }
}
