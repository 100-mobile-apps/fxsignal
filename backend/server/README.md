# Forex Signals Live — live server code

This folder mirrors what runs on the server (`/var/www/100apps/fxsignal`, served at
https://forexsignal.100apkgoals.com). It is the source of truth for the signal engine,
cron, website, admin panel and the `/bot` page. The older `backend/api` folder is the
original cPanel version and is kept only for reference.

- `src/` — signal engine (Engine, Cron, TradingPolicy, MarketRank…), used by `cron/run.php` every 20 minutes
- `api/` — mobile app API (`api/index.php`, `api/src/`)
- `web/`, `index.php` — website and member area; `admin/` — admin panel; `bot/` — trading-bot sales page

Not in git (server only): `src/config.php`, `src/config.premium.php`, `api/src/config*.php`,
`*.json` service-account keys, backups and archives. Copy the `*.sample.php` files to start a new install.
# Backend installation and operation

The backend is a PHP 8+ JSON API backed by MySQL/MariaDB. A separate **CLI**
cron worker retrieves OHLC candles from Twelve Data, updates prices, creates
and tracks signals, schedules reports, and processes outgoing email. It is not
a broker connection or an auto-trading system. Start with the
[package overview](../README.md), especially its production limitations.

## Requirements and accounts

- cPanel hosting with HTTPS, Apache/LiteSpeed-compatible `.htaccess` rewrites,
  PHP 8.0+ for **both the website and the cron CLI**, and a MySQL/MariaDB
  database. PHP extensions: `pdo_mysql`, `curl`, `openssl`, and `mbstring`.
  SMTP needs PHP's normal TLS-enabled socket support. Long cron runs may need
  a suitable CLI execution-time and memory allowance.
- A Twelve Data API key with access to the selected instruments/timeframe.
  Check the provider's **current** request and redistribution allowances;
  historical free-tier quotas stated elsewhere are not a guarantee.
- A real email mailbox/SMTP service for verification and password reset.
  Firebase project and service-account credentials are **optional** for push.
- A dedicated, empty database and a private place to store credentials.

There is no Composer installation and no prepopulated database. Do **not**
upload `backend/tests/`, `backend/schema.sql`, or your signing keystore to a
public website. The ready-made `fxsignals-cpanel.zip` is a snapshot of
`api/`, not a dynamically regenerated build: if you modify PHP, upload your
modified `api/` files instead of relying on an old ZIP.

## 1. Choose the URL and document root

In cPanel **Domains**, create your domain/subdomain, note its actual document
root, and provision a valid HTTPS certificate (typically AutoSSL). Wait for
DNS to resolve before testing remotely.

| Arrangement | What to upload | Public API URL | Worker script |
| --- | --- | --- | --- |
| Dedicated subdomain, such as `api.example.com` | **Contents** of `backend/api/` directly into that subdomain's document root | `https://api.example.com` | `<SUBDOMAIN_DOCROOT>/cron/run.php` |
| Existing site's `/api` path | `backend/api/` directory inside that site's `public_html/` | `https://example.com/api` | `/home/CPANELUSER/public_html/api/cron/run.php` |

For the subdomain arrangement, the final layout must look like this (not
`DOCROOT/api/index.php`):

```text
<SUBDOMAIN_DOCROOT>/
  .htaccess
  index.php
  src/
    .htaccess
    bootstrap.php
    config.sample.php
    ...PHP classes...
  cron/
    .htaccess
    run.php
```

In cPanel File Manager, enable **Show Hidden Files** before confirming the
three `.htaccess` files survived upload/extraction. `index.php` routes clean
paths using the main `.htaccess`; `src/` and `cron/` must be denied via HTTP.
If your web server does not honor these rules, move credentials outside its
document root and configure equivalent rewrite/deny rules **before exposing
the API**. `FX_CONFIG` can point PHP to an external configuration file if
your host makes that environment variable available to both web and cron PHP.

## 2. Create the database

In cPanel **MySQL Databases**, create an empty database and database user, then
grant that user the required privileges on that database. cPanel often
prefixes both names with your account name; use the final names shown there.
In **phpMyAdmin**, select the new database and import `backend/schema.sql`.

The schema creates `users`, `password_resets`, `signals`, `prices`,
`email_queue`, `rate_limits`, and `meta` with InnoDB and `utf8mb4`. There is
no sample user, sample signal or market history. `CREATE TABLE IF NOT EXISTS`
does **not** perform version-to-version migrations: back up the database
before changing a running installation, and inspect schema differences.

## 3. Configure the API

Copy `src/config.sample.php` to `src/config.php` on the host and fill in
private values. The app will return HTTP 500 until this file (or `FX_CONFIG`)
is available. Do not put `config.php`, a database dump, or secrets in a
downloadable marketplace archive; restrict filesystem permissions if the host
allows it. Keep `debug` false on public servers.

| Configuration | What to set or review |
| --- | --- |
| `db.host`, `db.name`, `db.user`, `db.pass` | cPanel's real database values; `db.dsn` is an advanced PDO override. |
| `jwt_secret` | Unique cryptographically random secret, preferably 40+ characters. Changing it invalidates existing sessions and unsubscribe signatures. The sample placeholder is rejected by token operations. |
| `twelvedata_key` | Your own Twelve Data key; absent key prevents candle retrieval. |
| `pairs`, `timeframe`, `requests_per_minute` | Default eight FX/gold pairs, `1h` candles, eight requests/minute throttling. Confirm the provider covers each pair and your plan's budget. |
| `min_confidence`, `signal_expiry_hours`, `cooldown_hours` | Defaults 65, 72 and 4. Changing these changes which signals and results are generated; test before publishing. |
| `base_url` | Exact public URL of `index.php`'s API directory, without `/health` or a trailing slash. Used in email unsubscribe links. Match the app build's `API_URL`. |
| `report_timezone` | IANA timezone such as `Africa/Lagos`; report periods and scheduling follow this local timezone. |
| `mail` | SMTP host, port, TLS mode, account, sender and queue batch size; see below. |
| `fcm.project_id`, `fcm.service_account_file` | Leave project ID empty to disable optional push; if enabled, point to a protected service-account JSON. |
| `app_name`, `developer`, `promo` | Server/email branding and on/off flags for app promotion links. Actual in-app URLs are in Flutter source. |
| `cors_origin`, `debug` | Restrict browser origins when appropriate; `*` is the sample default. `debug` must stay false publicly. CORS is not authentication. |

Do not use a sample JWT key, SMTP password or copied production credentials
for multiple customer installations. A user account in one customer's
database is unrelated to accounts in another customer's database.

### Email

Use cPanel **Email Accounts -> Connect Devices** (or your SMTP provider) for
the actual SMTP host, sender and credentials. Typical SMTP choices are port
465 with `secure = 'ssl'` or port 587 with `secure = 'tls'`; use the settings
your provider documents. `mail.transport` also supports PHP `mail` and a
test-only `log` transport. `mail.max_per_run` defaults to 40 **queued**
messages per worker run; immediate verification/reset emails are outside that
batch. Failed queue deliveries get up to three attempts before remaining in
`failed` state; there is no automatic retry of that state. Monitor provider
limits and queue size, and verify actual inbox delivery including spam folders.

Email eligibility is checked when an event is queued: the account must have a
verified address and the relevant preference enabled. Pair preferences filter
signal/result emails. The sample defaults to signal and result email on,
daily report email off, and weekly/monthly report email on. TP1 notifications
are push-only; final results may produce email. Queued messages are not
rechecked for a later preference change at delivery time.

### Optional Firebase push

Create a Firebase project for **your** app. Supply its project ID and a server
service-account JSON path in `config.php`; keep the JSON outside the document
root if possible. If you cannot, ensure HTTP requests for it are denied by
your server and restrict filesystem access. The Flutter app needs its own
Firebase app configuration for the **same project** (see
[app/README.md](../app/README.md)); a server service account is not an app
configuration file.

The backend uses FCM HTTP v1 topics such as `signals_EURUSD`,
`results_EURUSD` and `reports`. Topics are not a paid-access control system.
The health field `push_enabled` only indicates the project ID and service
account file exist, not that authentication or delivery works. Credential or
token-fetch failures can abort the worker after database changes; test and
isolate push failures before enabling FCM on a public deployment.

## 4. Smoke-test the HTTP API

With the example dedicated subdomain, test:

```text
https://api.example.com/health
https://api.example.com/src/config.php       (must be 403 or 404)
https://api.example.com/cron/run.php         (must be 403 or 404)
```

For an `/api` layout, prepend `/api` to these paths. `GET /health` requires
the database and should return JSON with `"ok":true`, configured pairs,
timeframe and `last_update`. A newly deployed installation has
`"last_update":null` until cron records a run. An OK response **does not**
test the Twelve Data key, JWT secret, SMTP login, Firebase delivery or
freshness of prices. Check that `src/config.sample.php` and any service-account
JSON are denied too. A 200 response for any protected resource is a
deployment failure, even if the browser does not render its content.

Use a throwaway staging email/account to test registration, emailed code,
verification, login and an authorized request. Requests with a body use JSON;
the base URL is the root or `/api` layout chosen above. For example:

```bash
curl -i https://api.example.com/health
curl -i -X POST https://api.example.com/auth/register \
  -H 'Content-Type: application/json' \
  --data '{"name":"Test Buyer","email":"test@example.com","password":"Example123","accepted_terms":true}'
curl -i https://api.example.com/market/prices \
  -H 'Authorization: Bearer YOUR_TEST_TOKEN'
```

The registration response is 201 with `{token,user}` if successful. The
sample mailbox address and password above are illustrative; use a mailbox
you control and avoid putting real credentials or long-lived JWTs in shared
terminal history. Registration can succeed even if its first verification
email fails, so confirm inbox receipt, resend, and SMTP/PHP error logs.

## 5. Enable and monitor cron

**Do not start cron until you have reviewed the production limitations in the
root README and tested your feed, mail and optional push on staging.** In
cPanel **Cron Jobs**, enter `*/20 * * * *` in the schedule fields. For the
command field, use the host's actual CLI PHP path and the **absolute**
subdomain document root:

```bash
/usr/local/bin/php "<ABSOLUTE_DOCUMENT_ROOT>/cron/run.php" >> /home/CPANELUSER/fxsignals-cron.log 2>&1
```

Replace `<ABSOLUTE_DOCUMENT_ROOT>` with the complete path cPanel shows, such
as `/home/alice/api.example.com` (do not prepend `/home/CPANELUSER/` twice).
For the `/api` layout, a common script path is
`/home/CPANELUSER/public_html/api/cron/run.php`. Your host may use a different
PHP binary; web PHP and CLI PHP must both have the required extensions. Keep
logs private, inspect them during setup, and rotate or remove old logs to
avoid filling disk. The worker is deliberately CLI-only and has a lock to
avoid simultaneous executions; do not invoke it from a browser. Its lock
filename is fixed in the system temporary directory: if you run multiple
installations on the same server, give each installation a unique lock path
in `api/cron/run.php` before scheduling both or one can skip runs.

Each run fetches up to 300 candles per configured pair; with eight pairs and
three runs/hour, the schedule requests approximately 576 series/day before
retries or other API usage. The worker updates `prices`, replays running
signals, evaluates **closed** candles for new setups, queues reports/emails
and writes `meta.last_cron`. It is normal for a pair to say `no setup`,
`already evaluated`, or `market closed / stale data`. On `/health`, check
`last_update.at` is recent and inspect every per-pair log line. Treat missing
updates, feed errors and failed mail as operational incidents, not successful
market scans. Check current Twelve Data limits before changing the schedule,
number of pairs or timeframe.

Reports use `report_timezone`: daily on Monday-Friday after 22:00 for that
day; weekly after 08:00 Saturday for the week beginning Monday (a snapshot,
not a completed Sunday-end week); monthly after 08:00 on the 1st for the
previous month. They run on the next eligible cron invocation, not exactly
on the minute. The report marker is currently saved before notification
queueing, so an exception can leave that period unsent without retry.

## API reference

All paths are relative to the configured base URL. Unless marked public,
send `Authorization: Bearer <JWT>`. JSON errors normally have an `error`
field. Auth tokens expire after 30 days by default and are invalidated when
the user's password changes. Verification controls alert/report **email**
eligibility, not access to protected data endpoints.

| Method and route | Auth | Body, query and response |
| --- | --- | --- |
| `GET /`, `GET /health` | Public | Database-backed status, configured pairs/timeframe, `last_update`, `push_enabled`, `promo`. |
| `POST /auth/register` | Public | JSON `name`, `email`, `password`, `accepted_terms:true`; returns `{token,user}` (201) and attempts to email a 6-digit verification code. |
| `POST /auth/login` | Public | JSON `email`, `password`; returns `{token,user}`. |
| `POST /auth/forgot` | Public | JSON `email`; sends a reset code if an account exists without revealing which emails are registered. |
| `POST /auth/reset` | Public | JSON `email`, `code`, `password`; on success returns a new `{token,user}`. Codes last 15 minutes with up to five wrong attempts. |
| `POST /auth/verify` | JWT | JSON `code`; returns updated `{user}`. |
| `POST /auth/verify/resend` | JWT | Requests another verification email. |
| `GET /me` | JWT | `{user}` including watchlist and notification preferences. |
| `PUT /me` | JWT | JSON `name`; returns updated `{user}`. |
| `DELETE /me` | JWT | JSON `password`; deletes account, returns `{ok:true}` (see queue caveat below). |
| `PUT /me/password` | JWT | JSON `current_password`, `new_password`; returns replacement `{token,user}`. |
| `PUT /me/notifications` | JWT | JSON `signals`, `results`, `reports`, `pairs`, nested `email:{signals,results,daily,weekly,monthly}`; returns `{user}`. |
| `PUT /me/watchlist` | JWT | JSON `symbols` list; returns `{user}`. |
| `GET /signals` | JWT | `status=running|closed` (running default), `page=1`, optional `pair`. For `status=closed` only, `from` inclusive and `to` exclusive filter close time; omit them for running signals. `{items,page,has_more}`, 20 items/page. Unknown status values fall back to running. |
| `GET /signals/{id}` | JWT | `{signal}` including stored chart candles. |
| `GET /stats` | JWT | `days=30` default; a positive integer is a trailing-day window, `0` means all history. Aggregate counts, pips, equity and per-pair/month data. |
| `GET /reports` | JWT | `period=daily|weekly|monthly` (daily default), optional `limit` clamped 1-60 (defaults 20/12/12). `{period,timezone,summary,items}`. |
| `GET /market/prices` | JWT | `{items}` with latest stored price, change, high/low and sparkline per available pair. |
| `GET` or `POST /email/unsubscribe` | Signed link | Query `u`, `k`, `s` from email footer; returns HTML and **changes email settings even on GET**. |

For source-level changes: `api/index.php` maps routes; `src/Auth.php` and
`src/Account.php` implement user handling; `src/TwelveData.php` fetches
candles; `src/Engine.php` scores EMA, MACD, RSI and ATR; `src/Cron.php` opens
signals and updates prices; `src/Tracker.php` replays outcomes; `src/Stats.php`
and `src/Reports.php` aggregate them; `src/Notify.php`, `src/Mailer.php`,
`src/Fcm.php` and `src/EmailTemplates.php` handle delivery. Data structure
changes also require SQL migrations, API serialization and Dart model updates.

## Known limits and diagnostics

- **Signal returns need review before public performance claims.** The
  tracker counts full TP1 pips/price when remaining exposure returns to
  break-even or expires after TP1, without storing position sizing. It also
  skips the entire candle in which a signal opens. OHLC candles cannot prove
  the intrabar event order or actual broker fills; stops are checked before
  targets as a conservative convention. See `api/src/Tracker.php` and the
  package overview. Treat pips as modeled signal scores until you specify
  and test a defensible trade-accounting methodology.
- A data outage can leave gaps in tracking; a later expiry may use an older
  quote. FCM auth failures and a report-queue failure can interrupt/skip
  delivery after other state has changed. Monitor logs, not just `/health`.
- Account deletion does not currently purge queued email; the queue retains
  addresses and message content. Failed messages are not automatically
  purged. Plan appropriate fixes and retention practices before collecting
  customer data.
- Signed unsubscribe links are accepted by GET as well as POST. Email-link
  scanners can trigger them. `cors_origin='*'` is permissive but not an
  authentication mechanism. FCM topic membership is not access control.
- If `/health` returns 500: confirm config path, PHP error log, DB host/user,
  imported schema and web PHP extensions. If `last_update` is null/stale:
  confirm the scheduled CLI binary, actual cron path and private cron log.
  If registration succeeds without email: test SMTP and resend. If push is
  disabled: confirm both app-side Firebase and protected server credentials.
- Backend fixtures under `tests/` are **not** safe smoke tests for a populated
  production database: `simulate.php` recreates its SQLite fixture and
  `e2e.php` writes to a test MySQL database and test SMTP server. The saved
  `tests/TEST_RESULTS.txt` result (24 passes) used synthetic price data and
  is not proof this deployment, feed or live performance works.

Before updating a running installation, back up the database and private
config, deploy changed PHP files without exposing them, apply reviewed schema
migrations as needed, confirm health and protected paths, and verify one
complete cron cycle plus email on a staging host.
