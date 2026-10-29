<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy policy · Green</title>
    <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
    <style>
        :root {
            --background: #fafafa;
            --card: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --muted: #52525b;
            --link: #047857;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #09090b;
                --card: #18181b;
                --border: #27272a;
                --text: #f4f4f5;
                --muted: #a1a1aa;
                --link: #34d399;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 3rem 1rem;
            background: var(--background);
            color: var(--text);
            font-family: "Inter Variable", Inter, ui-sans-serif, system-ui, sans-serif;
            font-size: 1rem;
            line-height: 1.6;
        }

        main {
            max-width: 42rem;
            margin: 0 auto;
            padding: 2.5rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
        }

        h1 { margin: 0; font-size: 1.75rem; line-height: 1.25; }
        h2 { margin: 2rem 0 0.5rem; font-size: 1.125rem; }
        p, ul { margin: 0.5rem 0; }
        ul { padding-left: 1.25rem; }
        li + li { margin-top: 0.25rem; }
        a { color: var(--link); }
        .muted { color: var(--muted); }
        .back { display: inline-block; margin-top: 2rem; }

        @media (max-width: 640px) {
            body { padding: 1rem; }
            main { padding: 1.5rem; }
        }
    </style>
</head>
<body>
    <main>
        <h1>Privacy policy</h1>
        <p class="muted">Green · Last updated October 29, 2026</p>

        <p>
            Green is an open-source personal finance app. This copy of Green is run privately by its owner
            for their own bank accounts and for anyone they have given a login. It has no public sign-up.
        </p>

        <h2>What Green stores</h2>
        <ul>
            <li>Your login: name, email address, a hashed password, and your two-factor authentication secret and recovery codes (encrypted).</li>
            <li>Sign-in sessions, which record your IP address and browser.</li>
            <li>From each bank you connect through Plaid: the bank's name; each account's name, type, last few digits and balances; and its transactions, including the date, amount, description, merchant, category and the rest of the record Plaid provides, which can include the merchant's location.</li>
            <li>What you add yourself, such as Books, categories, recurring bills, budgets and expected income.</li>
            <li>A log of Green's activity with Plaid, so sync problems can be spotted: for each sync, live balance check, bank link or removal, the bank's name, when it ran and what started it, how long it took, how many transactions it added, updated or removed, whether it worked (with Plaid's error code and message if not), and Plaid's ID for each request. The log never holds access tokens or any transaction details, and each entry is deleted after 14 days.</li>
        </ul>
        <p>
            Green never sees your bank username or password: you enter them with Plaid. It doesn't request full account
            or routing numbers, or identity details such as your address.
        </p>

        <h2>How it's used</h2>
        <p>
            Only to show you your balances and transactions, categorize them, track recurring bills, budgets and income,
            and forecast your balances. Green has no ads, analytics or tracking, and never sells or shares your data.
            The only other party involved is Plaid, as described below.
        </p>

        <h2>Plaid</h2>
        <p>
            Green connects to banks through Plaid Inc. When you link a bank, Plaid collects data from it under the
            <a href="https://plaid.com/legal/#end-user-privacy-policy" rel="noopener">Plaid End User Privacy Policy</a>
            and passes it to Green. Green keeps an access token for each connection, encrypted, and uses it to sync about
            once an hour. You can also review and revoke your connections at
            <a href="https://my.plaid.com" rel="noopener">my.plaid.com</a>.
        </p>

        <h2>Where it's kept and how it's protected</h2>
        <p>
            On this installation's own server and database. Green is served over HTTPS, encrypts Plaid access tokens
            and two-factor secrets, and requires two-factor sign-in. Each user sees only their own data.
        </p>

        <h2>How long it's kept, and deleting it</h2>
        <p>
            Data from a bank is kept while that bank stays connected. Removing a bank connection in Green revokes
            Green's access at Plaid and deletes that connection's accounts and transactions; its entries in the Plaid
            activity log stay until they are 14 days old, like every entry there. To delete everything,
            including your login, ask the owner of this installation. Server backups, if any, are replaced on their
            normal schedule.
        </p>

        <h2>Changes and contact</h2>
        <p>
            This policy is reviewed at least once a year, and the date above shows when it last changed.
            @if (filled($contact = config('green.privacy_contact')))
                For questions or deletion requests, email <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
            @else
                For questions or deletion requests, contact the owner of this installation.
            @endif
        </p>

        <a class="back" href="{{ url('/') }}">Back to Green</a>
    </main>
</body>
</html>
