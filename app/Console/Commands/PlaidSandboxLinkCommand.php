<?php

namespace App\Console\Commands;

use App\Enums\PlaidLogTrigger;
use App\Models\Book;
use App\Services\Plaid\LinkPlaidItem;
use App\Services\Plaid\PlaidClient;
use App\Services\Plaid\PlaidException;
use App\Services\Plaid\SyncPlaidItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plaid:sandbox-link
    {book : The id of the Book the new accounts should be added to}
    {--institution=ins_109508 : Sandbox institution id (default: First Platypus Bank)}
    {--username=user_transactions_dynamic : Sandbox test user}')]
#[Description('Link a Plaid Sandbox bank to a Book without the browser flow (development only)')]
class PlaidSandboxLinkCommand extends Command
{
    public function handle(PlaidClient $plaid, LinkPlaidItem $link, SyncPlaidItem $sync): int
    {
        if (! $plaid->isSandbox()) {
            $this->components->error('This command only works with PLAID_ENV=sandbox.');

            return self::FAILURE;
        }

        $book = Book::query()->find($this->argument('book'));

        if (! $book) {
            $this->components->error("No Book with id {$this->argument('book')}.");

            return self::FAILURE;
        }

        try {
            $publicToken = $plaid->sandboxCreatePublicToken(
                $this->option('institution'),
                $this->option('username'),
            )['public_token'];

            $item = $link->handle($book->user, $book, $publicToken);
        } catch (PlaidException $e) {
            $this->components->error($e->userMessage());

            return self::FAILURE;
        }

        $this->components->info("Linked {$item->institution_name} with {$item->accounts()->count()} account(s) to \"{$book->name}\".");

        $result = $sync->handle($item, PlaidLogTrigger::Link);

        $result->successful
            ? $this->components->info('Transactions synced: '.$result->summary())
            : $this->components->warn('Sync failed: '.$result->summary());

        if ($result->successful && $result->added === 0) {
            $this->components->warn('Plaid is still preparing transactions. Run `php artisan plaid:sync` in a minute.');
        }

        return self::SUCCESS;
    }
}
