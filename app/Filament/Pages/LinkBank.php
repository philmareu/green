<?php

namespace App\Filament\Pages;

use App\Enums\PlaidItemStatus;
use App\Enums\PlaidLogTrigger;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\PlaidItems\PlaidItemResource;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Services\Plaid\LinkPlaidItem;
use App\Services\Plaid\PlaidClient;
use App\Services\Plaid\PlaidException;
use App\Services\Plaid\SyncPlaidItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Runs Plaid Link. Open with `?book=<id>` to add a bank to a Book, or with
 * `?item=<id>` to re-authenticate an existing connection (update mode).
 */
class LinkBank extends Page
{
    protected string $view = 'filament.pages.link-bank';

    protected static ?string $slug = 'link-bank';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public ?int $bookId = null;

    #[Locked]
    public ?int $itemId = null;

    #[Locked]
    public ?string $linkToken = null;

    #[Locked]
    public ?string $receivedRedirectUri = null;

    public ?string $errorMessage = null;

    public function mount(PlaidClient $plaid): void
    {
        $this->bookId = $this->book(request()->integer('book') ?: null)?->getKey();
        $this->itemId = $this->item(request()->integer('item') ?: null)?->getKey();

        // Returning from an OAuth bank: resume the same Link session.
        if (request()->boolean('oauth') && session()->has('plaid.link.token')) {
            $this->linkToken = session('plaid.link.token');
            $this->receivedRedirectUri = session()->pull('plaid.link.received_redirect_uri');

            return;
        }

        try {
            $this->linkToken = $plaid->createLinkToken(auth()->user(), $this->item($this->itemId))['link_token'];
        } catch (PlaidException $e) {
            $this->errorMessage = $e->userMessage();

            return;
        }

        session()->put('plaid.link.token', $this->linkToken);
        session()->put('plaid.link.return_url', static::getUrl(array_filter([
            'book' => $this->bookId,
            'item' => $this->itemId,
        ])));
    }

    public function getTitle(): string|Htmlable
    {
        if ($this->itemId) {
            return 'Reconnect '.($this->item($this->itemId)->institution_name ?? 'bank');
        }

        return $this->bookId ? 'Link a bank to '.$this->book($this->bookId)->name : 'Link a bank';
    }

    public function destinationName(): string
    {
        return $this->book($this->bookId)?->name ?? 'Unassigned';
    }

    /**
     * Called by Plaid Link's onSuccess callback in the browser.
     */
    public function exchange(string $publicToken, ?string $institutionId = null, ?string $institutionName = null): void
    {
        $sync = app(SyncPlaidItem::class);

        if ($item = $this->item($this->itemId)) {
            // Update mode: the existing access token works again; no exchange needed.
            $item->forceFill(['status' => PlaidItemStatus::Active, 'error_code' => null, 'error_message' => null])->save();
            $result = $sync->handle($item, PlaidLogTrigger::Reconnect);

            $this->finish("{$item->institution_name} reconnected", $result->summary(), PlaidItemResource::getUrl());

            return;
        }

        $book = $this->book($this->bookId);

        try {
            $item = app(LinkPlaidItem::class)->handle(auth()->user(), $book, $publicToken, $institutionId, $institutionName);
        } catch (PlaidException $e) {
            $this->linkFailed($e->errorCode, $e->userMessage());

            return;
        }

        $result = $sync->handle($item, PlaidLogTrigger::Link);
        $accounts = $item->accounts()->count();

        $this->finish(
            "Linked {$item->institution_name}",
            "{$accounts} account(s) added. Transactions: {$result->summary()}"
                .($result->successful && $result->added === 0 ? ' Plaid may still be preparing history; it will appear on the next sync.' : ''),
            $book ? BookResource::getUrl('view', ['record' => $book]) : AccountResource::getUrl(),
        );
    }

    /**
     * Called by Plaid Link's onExit callback when Link reports an error.
     */
    public function linkFailed(?string $code = null, ?string $message = null): void
    {
        $this->errorMessage = $message ?: 'Plaid Link closed with an error'.($code ? " ({$code})." : '.');

        Notification::make()->title('Bank link failed')->body($this->errorMessage)->danger()->send();
    }

    protected function finish(string $title, string $body, string $redirectTo): void
    {
        session()->forget(['plaid.link.token', 'plaid.link.return_url', 'plaid.link.received_redirect_uri']);

        Notification::make()->title($title)->body($body)->success()->send();

        $this->redirect($redirectTo, navigate: true);
    }

    protected function book(?int $id): ?Book
    {
        return $id ? Book::query()->ownedBy(auth()->user())->findOrFail($id) : null;
    }

    protected function item(?int $id): ?PlaidItem
    {
        return $id ? PlaidItem::query()->ownedBy(auth()->user())->findOrFail($id) : null;
    }
}
