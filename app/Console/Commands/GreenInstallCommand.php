<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

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

        if (User::query()->where('email', DemoSeeder::EMAIL)->exists()) {
            $this->components->error(sprintf(
                'This database holds the demo, and its login (%s) has a password anyone can look up. Run `php artisan migrate:fresh` to delete the demo, then run this command again.',
                DemoSeeder::EMAIL,
            ));

            return self::FAILURE;
        }

        if (blank(config('services.plaid.client_id')) || blank(config('services.plaid.secret'))) {
            $this->components->warn('PLAID_CLIENT_ID and PLAID_SECRET are not set yet. Add them to .env before linking a bank.');
        }

        $this->components->info('Create the account you will sign in with.');

        $name = $this->option('name') ?? text('Name', required: true);
        $email = $this->option('email') ?? text('Email address', required: true, validate: $this->emailError(...));
        $password = $this->option('password') ?? password('Password', required: true, validate: $this->passwordError(...), hint: 'At least 8 characters.');

        // Values given as options skip the prompts, so check them here too.
        if ($error = $this->emailError($email) ?? $this->passwordError($password)) {
            $this->components->error($error);

            return self::FAILURE;
        }

        $status = $this->call('make:filament-user', [
            '--name' => $name,
            '--email' => $email,
            '--password' => $password,
        ]);

        if ($status === self::SUCCESS) {
            $this->components->info('Done. Start the app with `php artisan serve` and sign in.');
        }

        return $status;
    }

    private function emailError(string $email): ?string
    {
        return $this->firstError($email, ['required', 'email', 'max:255', Rule::unique(User::class, 'email')], 'email');
    }

    private function passwordError(string $password): ?string
    {
        return $this->firstError($password, ['required', Password::defaults()], 'password');
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function firstError(string $value, array $rules, string $field): ?string
    {
        return Validator::make([$field => $value], [$field => $rules])->errors()->first($field) ?: null;
    }
}
