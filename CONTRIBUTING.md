# Contributing to Green

Green is a personal project. I built it for my own books, I use it every day, and I maintain it alone in my spare time. It's MIT licensed and not commercially supported. If you host it yourself, you do that at your own risk.

Issues and pull requests are open, because a bug report on a finance app is worth a lot. What I can't offer is a quick reply, or a promise to take up every request. This page says what to expect, so you know before you put time in.

## What to expect

- **Bug reports** are welcome. Use the [bug report form](https://github.com/philmareu/green/issues/new/choose). A report may sit for a while before I get to it. Issues are public, so leave out bank data, Plaid tokens, `.env` contents and unredacted logs.
- **Feature requests** can be filed, but I may close one without action if it doesn't fit what I need from Green. Forking is encouraged, and for most "I wish it did X" it's the right answer.
- **Pull requests** that are small, focused bug fixes with a test are the most likely to be merged. For anything larger, open an issue first. I may decline a large pull request I wasn't expecting, however good it is. Review may be slow.
- **Security vulnerabilities** never go in a public issue. Report them privately, as [SECURITY.md](SECURITY.md) describes. They come first.
- **Setting up Plaid or hosting PHP** is outside what I can help with. [Plaid's docs](https://plaid.com/docs/) and [Laravel's deployment guide](https://laravel.com/docs/deployment) are the places to look.
- There are no Discussions, no wiki and no chat. Issues and pull requests are all there is.

## Getting set up

You need PHP 8.4 or 8.5 and Composer 2. The [README](README.md) lists the PHP extensions.

```bash
git clone https://github.com/philmareu/green.git
cd green
composer setup               # install dependencies, create a development .env, an app key and a SQLite database
php artisan migrate --seed   # create the tables and load the demo
php artisan serve
```

Open the URL it prints and sign in as `test@example.com` with the password `password`.

The demo is made-up Books, accounts and transactions, so you don't need Plaid keys to work on most of Green. Its accounts aren't linked to a bank, so **Sync now** fails on them. For anything that talks to Plaid, free [Sandbox keys](https://dashboard.plaid.com/signup) are enough.

`php artisan db:seed` loads the demo into a database that is already migrated, and running it again rebuilds the demo from scratch. Its login has a password anyone can look up, so the demo refuses to run in production and in a database that already has another login.

## Before you open a pull request

- `composer test` passes. The tests fake every Plaid request with `Http::fake()`, and a request that isn't faked fails the test. Keep it that way.
- `vendor/bin/pint` has formatted your code.
- New behavior has a test. Plaid response fixtures live in `tests/Pest.php`.
- No real keys, tokens or bank data anywhere: not in code, fixtures, screenshots or commit messages.
- It does one thing. For anything larger than a bug fix, open an issue first so we can agree on the approach before you write it.

GitHub runs the tests on PHP 8.4 and 8.5, and the Pint check, on every pull request. On your first pull request, the run waits for me to approve it.

## Conventions

- Plaid amounts are stored with Plaid's sign (positive = money out). Flip the sign only for display, with `Transaction::signed_amount`.
- Plaid calls go through `App\Services\Plaid\PlaidClient`. Business logic lives in small classes under `app/Services`, not in Filament resources.
- Every query a user can reach is scoped to them with the `ownedBy()` model scopes.
- Green has no custom Filament theme, so Tailwind classes in your own Blade views aren't compiled. Use Filament's Blade components or inline styles.
- Tests run on SQLite, but Green has to work on MySQL too. Compare dates with `whereDate()`, never with string equality, and check foreign key changes against MySQL.
- Never write Plaid tokens or transaction details to a log. `/privacy` (`resources/views/privacy.blade.php`) lists what Green stores and shares. If your change alters either, update the page and its "Last updated" date.
