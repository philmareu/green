<?php

namespace App\Jobs;

use App\Enums\PlaidLogTrigger;
use App\Models\PlaidItem;
use App\Services\Plaid\SyncPlaidItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued by `plaid:sync`, which the scheduler runs hourly.
 */
class SyncPlaidItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public PlaidItem $item) {}

    public function uniqueId(): string
    {
        return (string) $this->item->getKey();
    }

    public function handle(SyncPlaidItem $sync): void
    {
        // Plaid errors are recorded on the item rather than failing the job.
        $sync->handle($this->item, PlaidLogTrigger::Scheduled);
    }
}
