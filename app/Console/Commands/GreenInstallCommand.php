<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('green:install
    {--name= : Your name}
    {--email= : The email you will sign in with}
    {--password= : Your password (min. 8 characters)}')]
#[Description('Set up the database and create your first login')]
class GreenInstallCommand extends Command
{
    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        if (blank(config('services.plaid.client_id')) || blank(config('services.plaid.secret'))) {
            $this->components->warn('PLAID_CLIENT_ID and PLAID_SECRET are not set yet. Add them to .env before linking a bank.');
        }

        $this->components->info('Create the account you will sign in with.');

        $status = $this->call('make:filament-user', array_filter([
            '--name' => $this->option('name'),
            '--email' => $this->option('email'),
            '--password' => $this->option('password'),
        ]));

        if ($status === self::SUCCESS) {
            $this->components->info('Done. Start the app with `php artisan serve` and sign in.');
        }

        return $status;
    }
}
