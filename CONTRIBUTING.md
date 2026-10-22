# Contributing to Green

Thanks for helping. Bug reports, ideas and pull requests are all welcome.

## Getting set up

```bash
git clone https://github.com/philmareu/green.git
cd green
composer setup
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # sample data; sign in as test@example.com / password
php artisan serve
```

You don't need Plaid keys to work on most of Green. For anything that talks to Plaid, free [Sandbox keys](https://dashboard.plaid.com/signup) are enough.

## Before you open a pull request

- `composer test` passes. Tests fake every Plaid request with `Http::fake()`; please keep it that way, and never commit real keys.
- `vendor/bin/pint` has formatted your code.
- New behavior has a test. Plaid response fixtures live in `tests/Pest.php`.
- For anything larger than a bug fix, open an issue first so we can agree on the approach.

## Conventions

- Plaid amounts are stored with Plaid's sign (positive = money out). Flip the sign only for display.
- Plaid calls go through `App\Services\Plaid\PlaidClient`. Business logic lives in small classes next to it, not in Filament resources.
- Every query a user can reach is scoped to them with the `ownedBy()` model scopes.
