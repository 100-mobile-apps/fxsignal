# Forex Signals Live - buyer and deployment guide

This package contains source code for a Flutter client and a separate PHP/MySQL
signal service. It does not include a hosted API, market-data subscription,
database, SMTP account, Firebase project, iOS project, or your own Android
signing key. Buyers operate their own infrastructure and are responsible for
their branding, credentials, legal disclosures, and trading claims.

## Start here

1. Read [Backend setup](backend/README.md) to provision a cPanel domain,
   database, Twelve Data feed, SMTP, and scheduled worker.
2. Read [Flutter app setup](app/README.md) to install dependencies, choose demo
   versus live mode, rebrand, configure optional Firebase push, and sign builds.
3. Review [Production limitations](#production-limitations) before enabling
   public signups or presenting any results as a verified trading record.

## Package map

| Location | Purpose |
| --- | --- |
| `app/lib/` | Flutter screens, models, state, API clients, simulated demo engine, themes and services. |
| `app/android/` | Generated Android project and environment-variable release signing. |
| `app/web/` | Generated web preview project; this product is documented as a mobile app. |
| `app/test/` | Flutter unit/widget checks. |
| `backend/api/` | PHP HTTP router, business logic and CLI cron worker. |
| `backend/schema.sql` | Empty MySQL/MariaDB schema; import into a new database. |
| `backend/fxsignals-cpanel.zip` | Snapshot of `backend/api/` for a cPanel upload; rebuild it after changing PHP code. |
| `backend/tests/` | Synthetic fixtures and a read-only historical CSV backtester; **no** real market history or proven performance is included. See [validation instructions](backend/README.md#validate-a-strategy-before-claiming-a-win-rate). |
| `PLAY_STORE_LISTING.md` | Example listing copy; replace branding and substantiate all claims. |

There is currently no `app/ios/` project. Generate and configure one on macOS
with Xcode if iOS is part of your product. Never upload the Flutter source,
SQL file, tests, APK, or signing keystore into the API document root.

## Data flow

```text
Twelve Data hourly OHLC candles
           |
           v
PHP cron (suggested every 20 minutes) -> MySQL prices, signals, reports, mail queue
           |                                    |
           +-- optional Firebase push             +-- SMTP delivery
                                                |
Flutter app <--------- HTTPS JSON API + JWT -------+
```

The worker, not the phone, obtains market prices. Signals and reports are
modeled from candles, not imported broker trades or guaranteed execution.
New publications pause Friday 21:00 UTC through Monday 00:00 UTC; existing
signals continue to be tracked. Candidate trend, RSI and entry-drift guards
may reduce signal frequency, but do not establish a higher live win rate.
Prices are refreshed on cron runs, not streamed tick by tick. An empty
`API_URL` switches the app to **locally simulated demo data**, which must not
be advertised as actual market performance.

## Quick setup order

1. Obtain a cPanel account with PHP 8+, MySQL/MariaDB and HTTPS, a Twelve Data
   API key, and an SMTP mailbox. Firebase is optional for push notifications.
2. Create a dedicated API subdomain or an `/api` path, import
   `backend/schema.sql`, deploy **the contents** of `backend/api/` to the
   chosen API directory, and configure `src/config.php` privately. Follow
   [Backend setup](backend/README.md) for both URL layouts and validation.
3. Confirm the API's `/health` responds over HTTPS and protected files cannot
   be fetched. Test registration and real verification email on a staging
   database. Do not interpret `"ok":true` alone as proof the worker or mail
   works.
4. Resolve the [production limitations](#production-limitations) and then
   enable the CLI worker on the host. Check recent `last_update` and per-pair
   logs; monitor the Twelve Data request allowance and SMTP limits.
5. Rebrand the client, configure a **new** Android signing key, and build with
   `--dart-define=API_URL=https://YOUR_API_HOST` (or append `/api` if deployed
   under that path). Follow [Flutter app setup](app/README.md). Verify the
   running app can log in against your host before publishing.

For a dedicated subdomain with `backend/api/index.php` directly at its document
root, the base URL is `https://api.example.com` and health is
`https://api.example.com/health`. For `public_html/api/index.php`, the base
URL is `https://example.com/api` and health is
`https://example.com/api/health`. Use the **same base** in backend `base_url`
and the Flutter build's `API_URL`. Do not add `/api` to the dedicated
subdomain example unless you really deployed a second `api/` directory.

## Production limitations

- **Review signal accounting before claiming a track record.**
  `backend/api/src/Tracker.php` credits full TP1 pips and a TP1 close price
  after a subsequent modeled break-even stop or expiry; no position-sizing or
  partial-close accounting is recorded. It also skips the entire candle in
  which a signal is opened, so some post-publication price movement is not
  tracked. These behaviors affect win rate and reported returns.
- A failed optional Firebase authentication can interrupt a worker run after
  database changes, and scheduled report completion is marked before delivery
  is queued. Validate failure/retry behavior before enabling push or making
  delivery guarantees.
- Account deletion currently does not remove queued email for that user;
  queued messages can still be sent. Define retention, deletion and email
  consent policies appropriate to your jurisdiction.
- `/health` confirms a database-backed response, **not** market-data,
  notification or SMTP readiness. The committed backend test result uses
  synthetic data and is not a verified live performance history.
- Check your market-data vendor's current licensing, rate limits and permitted
  redistribution. Review local rules for distributing trading signals. This
  software is not financial advice and does not guarantee any outcome.

Keep credentials outside public downloads and ensure the web server honors
the `.htaccess` restrictions. If distributing the source to customers, remove
your own credentials, Firebase service account and keystore first. Third-party
services, hosting and store accounts are the buyer's responsibility.
