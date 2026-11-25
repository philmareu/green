<?php

namespace App\Services\Plaid;

use App\Enums\PlaidItemStatus;
use App\Enums\PlaidLogKind;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\User;
use InvalidArgumentException;

/**
 * Turns the public token from a completed Link session into a stored Item and
 * its accounts. New accounts are placed in the Book the user started from;
 * they can be moved to another Book afterwards.
 */
class LinkPlaidItem
{
    public function __construct(
        private readonly PlaidClient $plaid,
        private readonly UpsertAccounts $upsertAccounts,
        private readonly PlaidLogger $logger,
    ) {}

    public function handle(
        User $user,
        ?Book $book,
        string $publicToken,
        ?string $institutionId = null,
        ?string $institutionName = null,
    ): PlaidItem {
        if ($book && $book->user_id !== $user->getKey()) {
            throw new InvalidArgumentException('That Book belongs to another user.');
        }

        return $this->logger->record(
            PlaidLogKind::Link,
            $user,
            fn (PlaidLog $log): PlaidItem => $this->link($log, $user, $book, $publicToken, $institutionId, $institutionName),
        );
    }

    protected function link(
        PlaidLog $log,
        User $user,
        ?Book $book,
        string $publicToken,
        ?string $institutionId,
        ?string $institutionName,
    ): PlaidItem {
        $log->institution_name = $institutionName;

        $exchange = $this->plaid->exchangePublicToken($publicToken);
        $accessToken = $exchange['access_token'];

        if (blank($institutionId)) {
            $institutionId = $this->plaid->getItem($accessToken)['item']['institution_id'] ?? null;
        }

        if (blank($institutionName) && filled($institutionId)) {
            $institutionName = $this->plaid->getInstitution($institutionId)['institution']['name'] ?? null;
        }

        $item = PlaidItem::query()->firstOrNew(['plaid_item_id' => $exchange['item_id']]);

        if ($item->exists && $item->user_id !== $user->getKey()) {
            throw new InvalidArgumentException('That bank connection belongs to another user.');
        }

        $item->fill([
            'user_id' => $user->getKey(),
            'access_token' => $accessToken,
            'institution_id' => $institutionId,
            'institution_name' => $institutionName,
            'status' => PlaidItemStatus::Active,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $log->forItem($item);

        $this->upsertAccounts->handle($item, $this->plaid->getAccounts($accessToken)['accounts'], $book);

        return $item;
    }
}
