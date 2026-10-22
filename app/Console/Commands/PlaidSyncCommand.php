<?php

namespace App\Console\Commands;

use App\Enums\PlaidItemStatus;
use App\Jobs\SyncPlaidItemJob;
use App\Models\PlaidItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plaid:sync {--item= : Only sync the Plaid item with this local id}')]
#[Description('Queue a transaction and balance sync for every connected bank')]
class PlaidSyncCommand extends Command
{
    public function handle(): int
    {
        $items = PlaidItem::query()
            ->when($this->option('item'), fn ($query, $id) => $query->whereKey($id))
            // Items waiting on the user to log in again will only fail until they do.
            ->where('status', '!=', PlaidItemStatus::LoginRequired)
            ->get();

        foreach ($items as $item) {
            SyncPlaidItemJob::dispatch($item);
        }

        $this->components->info("Queued sync for {$items->count()} bank connection(s).");

        return self::SUCCESS;
    }
}
