# Green

Self-hosted, open-source finance viewer. Users group bank accounts into **Books** (business, personal) and see balances and transactions, including pending ones, through Plaid. Laravel 13, Filament 5 (panel at `/`, id `admin`), Livewire 4, Pest 5. Public repo: keep secrets and personal data out of commits.

## Domain

- `User` → `Book` (business/personal) → `Account` → `Transaction`.
- `PlaidItem` = one bank login, owned by a **User**, not a Book. Its accounts can sit in different Books; `accounts.book_id` is nullable ("Unassigned").
- `transactions.amount` keeps **Plaid's sign: positive = money out**. Display with `Transaction::signed_amount`. Never flip it in storage.
- `CategorySet` (owned by a User, kind business/personal) → `Category`. Each Book picks one set (`books.category_set_id`, nullable); sets can be shared. Categories nest one level (`parent_id`), and a subcategory always takes its parent's `type` (income/expense/transfer), enforced in `Category::booted()`. Starter lists live in `app/Services/Categories/StarterCategories.php` (business = IRS Schedule C lines). Plaid's own category is kept separately on `transactions.category_primary`/`category_detailed`.
- `transactions.category_id` must be a category in the transaction's Book's set. `category_source` is, in order of precedence, `manual` (never replaced unless it leaves the Book's set), `recurring` (the category of the bill or income the transaction pays; may change), `payee` (the category with the longest `categories.payees` entry the description contains; a payee belongs to one category per set; may change) or `suggested` (from Plaid's category via `categories.plaid_categories`; may change). `app/Services/Categories/CategorizeTransactions.php` enforces all of this; it runs after each sync, when an account changes Book, when a Book changes set or is deleted, after category edits, and (through `Occurrence` and `Recurring` model events) when a payment is linked or unlinked, or a recurring item's category or kind changes or it is deleted. Green requests Plaid's PFC v2 codes (`app/Services/Categories/PlaidCategories.php`).
- `Recurring` (owned by a User; kind `bill`/`flex`/`income`; `amount` always positive, kind gives the direction) → `Occurrence` (one due date, or one flex period). A bill or income due date is paid by `occurrences.transaction_id`. `app/Services/Recurring/MatchRecurringTransactions.php` links one only when the transaction's description contains `recurrings.payee` (amount alone never matches), within `Frequency::matchWindowDays()` and `amount_tolerance`. Unlinked transactions go in `recurrings.dismissed_transactions` (Plaid ids, also checked against `pending_plaid_transaction_id`) and are never auto-linked again; manual links are always kept. A bill's or income's due date can be postponed (`occurrences.postponed_to`): `due_on` stays the scheduled date, and the forecast, overdue checks, next-due order and matching use `Occurrence::expectedOn()`. Due dates count from the item's `created_at` minus the window; earlier ones are kept only when a payment matched them (history). A bill's or income's category is optional and goes on its linked transactions. A bill's `autopay` flag only labels it (bills without it show "Pay by hand" in the lists); it doesn't change matching or the forecast. Flex spends link nothing: their category is required, and spending in it and its subcategories during the period counts against the budget. A period's budget is `Occurrence::budget()`: the amount, plus, when `recurrings.rolls_over`, every earlier tracked period's amount minus its spending (skipped periods carry nothing; all periods use the current amount). The forecast reserves what's left of the current period and takes its overspending out of later periods. Quarterly and yearly bills show a set-aside (`Recurring::monthlySetAside()` and `setAsideByNow()`, toward the next unpaid due date or, past the 90-day horizon, the next scheduled one) on the bill and in `SetAsideOverview` on the Recurring list; it's display only and doesn't change the forecast. `app/Services/Recurring/ForecastBalance.php` projects a cash account from its available balance, which already includes pending. The matcher runs after each sync (after the cursor is saved, errors are reported, not thrown) and when an item's matching fields change.
- `NotificationType` (enum) is one setting on the `NotificationPreferences` page (user menu); `users.notification_preferences` maps it to channels (`database`, `mail`), an empty list means off, and until saved it uses the type's defaults. `app/Services/Notifications/NotifyNegativeBalances.php` runs after each sync (errors are reported, not thrown) and alerts once per dip for visible cash accounts, tracked by `accounts.negative_notified_at`. Emails leave out the balance. A `Reminder` sits on a bill or income `Recurring` (never flex): `days_before`, an optional `note`, and `repeats` (one-off reminders are deleted once sent; repeating ones store `reminded_through`). `app/Services/Notifications/SendReminders.php` (`reminders:send`, daily at 08:00) reminds about the soonest open due date by `Occurrence::expectedOn()`, so paid or skipped ones are left out and postponed ones use the new date; it catches up on a missed morning until the due date, and errors are reported, not thrown. Reminder emails leave out the amount. `app/Services/Notifications/NotifyBudgetAlerts.php` runs after each sync (after categorizing and matching; errors are reported, not thrown) and alerts on a flex spend's current period at `Recurring::BUDGET_WARNING_PERCENT` of `Occurrence::budget()` and again when over, once per level per period (`occurrences.budget_alert`). Budget emails leave out the amounts.
- Every user-reachable query is scoped with the `ownedBy($user)` model scopes (Books, Items and CategorySets by `user_id`; Accounts and Transactions through `plaidItem.user_id`; Categories through `categorySet.user_id`; Recurrings by `user_id`; Occurrences and Reminders through `recurring.user_id`). Filament resources apply it in `getEloquentQuery()`.

## Plaid

- No official PHP SDK: `app/Services/Plaid/PlaidClient.php` wraps the REST API with the `Http` facade. Config in `config/services.php` → `plaid`, env `PLAID_*`.
- `SyncPlaidItem` uses `/transactions/sync`: pages are applied as they arrive (idempotent upserts and deletes), the cursor is saved only after `has_more` is false, and a posted transaction deletes its pending predecessor via `pending_transaction_id`.
- `LinkPlaidItem` exchanges a Link public token; `app/Filament/Pages/LinkBank.php` runs Link (`?book=` to add, `?item=` for update mode, `/plaid/oauth` return route in `routes/web.php`).
- Plaid errors become `PlaidException`; sync failures are recorded on the item (`status`, `error_code`, `error_message`) rather than thrown.
- `app/Services/Plaid/PlaidLogger.php` writes a `plaid_logs` row (Plaid Activity page, scoped by `user_id`) per sync, Live balance, link and removal, with each request's endpoint and `request_id` from `PlaidClient`; never tokens or transaction details. Write failures are reported, not thrown; the daily `model:prune` deletes rows after 14 days.

## Commands

- `composer test` (Pest; all Plaid HTTP is faked, `Http::preventStrayRequests()` is on). Fixtures: `plaidAccount()`, `plaidTransaction()`, `syncPage()`, `plaidError()` in `tests/Pest.php`.
- `vendor/bin/pint` before committing.
- `php artisan plaid:sync [--item=]` (scheduled hourly), `php artisan reminders:send` (scheduled daily at 08:00), `php artisan plaid:sandbox-link {book}`, `php artisan green:install`.
- `php artisan migrate --seed` on a new database, or `php artisan db:seed` on a migrated one, loads the demo (login `test@example.com` / `password`): Books, banks, about 90 days of transactions, recurring items, alerts and Plaid activity, all dated from today. `--class=DemoSeeder` does the same. It refuses (an exception, nothing written) in production, when the database has any other login, or once a real bank is linked to the demo login; otherwise a re-run rebuilds what the demo login owns and keeps the login. `database/seeders/DemoSeeder.php` builds it from the rows in `DemoStatements.php` through the app's own services and model events, so `DatabaseSeeder` must not use `WithoutModelEvents`.
- `.env.example` ships `APP_ENV=production` and `APP_DEBUG=false` for servers; `composer setup` writes `local` and `true` into the `.env` it creates.

## Gotchas

- No custom Filament theme: Tailwind utility classes in custom Blade views are not compiled. Use Filament Blade components or inline styles, or add a theme with `make:filament-theme`.
- Tests run on SQLite; check foreign key changes against MySQL too (MySQL refuses a self-referencing `nullOnDelete` inside a cascade).
- Book relation managers query transactions through a join with `accounts`; qualify column names (`$query->qualifyColumn()`) in custom sorts, filters and searches.
- Eloquent writes `date` casts with a time part on SQLite, while Plaid upserts write plain dates. Compare dates with `whereDate()` or `date(...)` in SQL, never with string equality.
- "Today", "overdue" and the forecast use `APP_TIMEZONE` (default UTC).
- `Recurring::orderByNextDue()` (the Recurring lists' default sort) and the `overdue()` scopes on `Recurring` and `Occurrence` (the red Recurring badges) repeat `Recurring::nextOccurrence()` and `Occurrence::status()` in SQL. Change them together; `tests/Feature/Recurring/RecurringTest.php` and `OccurrenceTest.php` check that they agree.
- `Recurring`, `Account` and `Book` refuse (an `InvalidArgumentException` on save) an account, Book or category set that belongs to another user, because several queries find rows through an account or a Book alone. A test that needs such a row has to write it with `withoutEvents()`.
- `plaid:sync` skips bank connections whose `plaid_item_id` starts with `DemoSeeder::ITEM_PREFIX`, and `green:install` refuses while the demo login exists.
- `/privacy` (`resources/views/privacy.blade.php`, public, linked from sign-in and Link bank) lists what Green stores and shares. Update it and its "Last updated" date when either changes.
- Sign-in uses Filament's authenticator-app MFA with recovery codes (`users.app_authentication_secret` and `app_authentication_recovery_codes`, both encrypted), required only when `APP_ENV=production`. Filament evaluates that when it registers routes, so a `route:cache` built outside production leaves MFA optional.
- Production installs with `--no-dev`, and `php artisan optimize` compiles every view file on deploy. A view file, or code that runs in production, that uses a dev dependency's component or class makes the deploy fail.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
