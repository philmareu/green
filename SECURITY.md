# Security policy

Green stores bank access tokens and transaction history, so security reports come before everything else on this project.

## Reporting a vulnerability

Don't open a public issue or pull request for a vulnerability. Report it through [GitHub's private vulnerability reporting](https://github.com/philmareu/green/security/advisories/new). Only you and I can see the report.

It helps to include:

- The commit you tested. `git rev-parse --short HEAD` prints it.
- What an attacker can do, and what they need first: nothing, a login, or access to the server.
- Steps to reproduce it, or a proof of concept. The demo data or a Plaid Sandbox bank is enough.
- A fix, if you have one in mind.

Leave out real bank data, Plaid tokens and anything from your `.env`, even though the report is private.

## What to expect

I maintain Green alone in my spare time. I can't promise a response time, and there is no bug bounty. Security reports are the first thing I look at, and I'll reply on the report as soon as I can.

When a report holds up, I fix it on `main` and publish a GitHub security advisory, with credit to you unless you'd rather not be named. Please give me the chance to do that before you publish the details.

## Scope

In scope is the code in this repository. For example:

- One user seeing or changing another user's Books, accounts, transactions or bank connections.
- Getting past sign-in or two-factor authentication.
- Plaid tokens or bank data ending up in logs, responses or the browser.

Out of scope:

- How a particular server is set up: HTTPS, the web server, file permissions, backups, or a `.env` that can be read from the web. That's up to whoever hosts it.
- Plaid's own API, Link and dashboard. Report those to Plaid.
- Vulnerabilities in Laravel, Filament or another dependency, unless the way Green uses it is what makes it exploitable. Report those to the project they belong to.

## Supported versions

There are no tagged releases yet. Security fixes go to the `main` branch, so the latest commit on `main` is the only supported version. To get a fix, update to it.

To hear about fixes, watch this repository's security advisories: **Watch**, then **Custom**, then **Security alerts**.
