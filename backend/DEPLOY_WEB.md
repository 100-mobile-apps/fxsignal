# Deploying the website, admin panel and affiliate programme

One PHP backend serves four things from the same domain, e.g. `https://forexsignal.100apkgoals.com`:

| Path | What | Code |
|---|---|---|
| `/` | Public website (SEO pages, results, pricing, affiliates) and the member area `/app` | `backend/web/` |
| `/api/` | JSON API used by the Android app (unchanged URLs) and the Paystack webhook | `backend/api/` |
| `/admin/` | Admin panel | `backend/admin/` |
| `/r/CODE` | Affiliate referral links | `backend/web/` |

The app keeps calling `https://forexsignal.100apkgoals.com/api`, so no app update is needed for the website.

## 1. Update the database (once)

Back up first, then run the migration against the live database:

```bash
mysqldump fxsignals > ~/fxsignals-before-web.sql
mysql fxsignals < backend/migrations/2026-11-web-affiliates.sql
```

It only adds columns (`users.referred_by`, `referred_at`, `blocked_at`) and new tables (`payments`, `web_subscriptions`, `affiliates`, `referral_clicks`, `commissions`, `payouts`). No existing data changes. A fresh install uses `backend/schema.sql`, which already includes them.

## 2. config.php additions

In `backend/api/src/config.php` add (see `config.sample.php`):

```php
'site_url' => 'https://forexsignal.100apkgoals.com',
'paystack' => ['secret_key' => 'sk_live_xxx'],   // sk_test_xxx while testing
```

`admin_emails` (in `config.premium.php`) must list your email. Sign in to `/admin/` with that app account.

## 3. nginx (Vultr)

Point the server block's root at `backend/web` and route `/api/` and `/admin/` to their own front controllers. Adjust the paths and PHP-FPM socket:

```nginx
server {
    server_name forexsignal.100apkgoals.com;
    root /var/www/fxsignal/backend/web;
    index index.php;
    client_max_body_size 2m;

    # Never serve code, config, tests or SQL
    location ~ ^/(api/src|api/cron|tests|migrations)/ { return 404; }
    location ~ \.(sql|md|json|lock|sh)$ { return 404; }

    location ^~ /assets/ { try_files $uri =404; expires 7d; access_log off; }

    location ^~ /api/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/fxsignal/backend/api/index.php;
        fastcgi_param SCRIPT_NAME /api/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location = /admin { return 301 /admin/; }
    location ^~ /admin/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/fxsignal/backend/admin/index.php;
        fastcgi_param SCRIPT_NAME /admin/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/fxsignal/backend/web/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

If the server already has a block for this domain that serves only the API, keep its `/api/` location and add the others. Then run `sudo nginx -t && sudo systemctl reload nginx`. The existing cron job (`api/cron/run.php`) also moves commissions out of the hold period. No new cron job is needed.

On Apache/cPanel instead: make `backend/web` the document root. `web/.htaccess` routes everything to `index.php`; place `api/` and `admin/` next to it with their own `.htaccess`.

## 4. Paystack

1. Paystack dashboard → Settings → API Keys & Webhooks:
   - Copy the secret key into config.php.
   - Set the webhook URL to `https://forexsignal.100apkgoals.com/api/billing/paystack`.
2. `/admin/` → Settings:
   - Set the currency and prices (default ₦2,500/month, ₦18,000/year), then **Create Paystack plans** so renewals charge automatically.
   - Set the naira per dollar rate. Commissions are kept in USD.
3. Test with `sk_test_` keys and a Paystack test card. Test payments unlock Premium but never pay commission.

## 5. Affiliate programme defaults (change in /admin → Settings)

- 30% of every payment (Google Play and Paystack) from referred users, for as long as they keep paying.
- 14-day hold (refund window), $10 minimum payout, 60-day referral cookie, affiliates approved automatically.
- Google Play commission is worked out from what Google actually pays you (after its 15% fee), as set in the same screen.
- Each affiliate can add their own broker IB link. After you approve it in /admin → Affiliates, the users they referred see it on "Open a broker account". Everyone else sees your default broker link.
- Payouts are manual: send the money, then mark the request paid with the reference. The affiliate gets an email.

## 6. Check after deploying

- `/` loads, and `/results` shows the live record.
- `/api/health` still returns `ok` (the app keeps working).
- `/admin/` → sign in → every tab loads.
- Register a test account through someone's `/r/CODE` link. /admin → Users shows "Referred by".

## Local testing

```bash
cd backend
mysql -e "CREATE DATABASE fxweb"; mysql fxweb < schema.sql      # plus user fxtest / fxtest-pass@127.0.0.1
FX_CONFIG=$PWD/tests/web/config.php php tests/web/seed.php
(cd tests && python3 fake_smtp.py 2526 &)
FX_CONFIG=$PWD/tests/web/config.php FX_PAYSTACK_MOCK=1 php -S 127.0.0.1:8093 dev-router.php &
python3 tests/web/e2e_web.py      # 67 checks: pages, referral, Paystack checkout + webhook, renewals, payouts, admin
```

The Paystack mock (`tests/web/paystack_mock.php`) only loads with `FX_PAYSTACK_MOCK=1` through `dev-router.php`. Production never loads it.
