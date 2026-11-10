# Publishing the Premium update (v1.1.0, build 5)

> **Status (10 Nov 2026):** server updated by Claude on forexsignal.bulksend.top. Both code copies were updated: `api/`, which the app uses, and the top level, which cron runs. The database is migrated. Settings live in `src/config.premium.php` in both copies, and the lock is off (`premium_enforced` false). A backup is in `backup-20261110-premium/`. The Google key goes to `api/src/play-service-account.json`, which both copies use.

Do the parts **in this order**. The server goes first, with locking off, so old app versions keep working. The app goes second. Locking turns on only on launch day (Part 4, step 9).

---

## Part 1: Google Play Console (subscriptions)

### 1.1 Merchant account (skip if you already have one)
1. Play Console → **Settings → Payments profile** → create the payments profile / merchant account.
2. Nigerian accounts are paid in **US dollars**, so link a **USD domiciliary account** for payouts.
3. Google keeps **15%** of subscription revenue, so you receive about $4.25 of each $5 and about $30.60 of each $36.

### 1.2 Upload an AAB with billing to a test track first
Play Console only lets you create subscriptions once an app bundle that contains Google Play Billing has been uploaded.
1. **Testing → Internal testing → Create new release**, then upload `app-release.aab` (build 5).
2. Add your own Gmail under **Testers** and save. You don't need to roll it out to production yet.

### 1.3 Create the two subscriptions
Play Console → your app → **Monetize with Play → Products → Subscriptions → Create subscription**

| Product ID (exact) | Name | Base plan ID | Billing period | Price |
|---|---|---|---|---|
| `fx_premium_monthly` | Premium Monthly | `monthly` | Monthly, auto-renewing | **$5.00** |
| `fx_premium_yearly` | Premium Yearly | `yearly` | Yearly, auto-renewing | **$36.00** |

For each one:
1. Add the base plan and set the price in **USD**. Click **Set prices** → **Update exchange rates**, and Google fills in naira and other currencies.
2. **Add offer → Free trial**: Offer ID `trial7`, eligibility **New customer acquisition / Never had this subscription**, phase **Free trial, 7 days**. Activate the offer.
3. **Activate** the base plan.

> The product IDs must match exactly. The app and server look for `fx_premium_monthly` and `fx_premium_yearly`.

### 1.4 Licence testers (buy without being charged)
Play Console (all apps) → **Settings → License testing** → add your Gmail and your other developer's. Their purchases are free, and test subscriptions renew every few minutes, so you can watch renewals and expiry happen fast.

---

## Part 2: Let the server check purchases with Google

### 2.1 Service account
1. Go to <https://console.cloud.google.com>. Use the same Google account, and create a project if you don't have one.
2. **APIs & Services → Library**: enable **Google Play Android Developer API**.
3. **IAM & Admin → Service accounts → Create service account**: name it `play-billing`. No roles are needed here.
4. Open it → **Keys → Add key → JSON**. A file downloads; rename it `play-service-account.json`.
5. Copy the service account's email (`play-billing@…iam.gserviceaccount.com`).
6. Play Console → **Users and permissions → Invite new users**:
   - Email: the service account's email.
   - **App permissions**: add Forex Signals Live and tick **View financial data, orders and cancellation survey responses** and **Manage orders and subscriptions**.
   - Invite. It can take up to 24 hours before Google accepts its requests, though it's usually minutes.

### 2.2 Real-time notifications (cancellations and renewals reach the server instantly)
This is optional. Without it, the server re-checks every subscription at least once a day and just before it renews.
1. Cloud Console → **Pub/Sub → Topics → Create topic** `play-rtdn`.
2. On the topic: **Permissions → Add principal** `google-play-developer-notifications@system.gserviceaccount.com` with role **Pub/Sub Publisher**.
3. **Create subscription** on the topic: delivery type **Push**, endpoint
   `https://YOUR-DOMAIN/api/billing/rtdn?secret=YOUR_RTDN_SECRET`
   (`YOUR_RTDN_SECRET` = any long random string; you'll put the same one in config.php).
4. Play Console → your app → **Monetize with Play → Monetization setup → Real-time developer notifications**: topic `projects/YOUR-PROJECT/topics/play-rtdn` → **Send test notification** → Save.

---

## Part 3: Update the server (cPanel)

Back up first: cPanel → **Backup** → download the database, and zip the `api` folder from File Manager.

1. **Database**: cPanel → phpMyAdmin → select the Forex Signals database → **Import** → `backend/migrations/2026-11-premium.sql`. This only adds columns and tables; no existing data changes.
2. **Upload the new code**: File Manager → open your live `api/` folder → **Upload** `fxsignals-premium-update.zip` (same as `backend/fxsignals-cpanel.zip`) → right-click it → **Extract** into that same folder and allow it to overwrite. It doesn't contain `config.php`, so your settings stay untouched. Delete the zip afterwards.
3. **Upload `play-service-account.json`** into `api/src/`, next to `config.php`. The folder already blocks web access to it.
4. **Edit `api/src/config.php`** using `config-changes.txt`. The block there is ready to paste, with a notification secret already generated. Leave **`'premium_enforced' => false`** for now: nothing gets locked, every user keeps full signals, and the new 4-hour strategy starts working right away.
5. **Check it**: open `https://YOUR-DOMAIN/api/health` in a browser. It should load as before. The cron job you already have doesn't need to change.

**What happens to signals already running:** they keep being tracked to the end. New signals use strategy v2 on the next 4-hour candle.

---

## Part 4: Build and release the app

1. **API address**: the build uses `https://forexsignal.bulksend.top/api` (set in `.github/workflows/build.yml`; a repository variable `API_URL` overrides it).
2. **Signing**: the update must be signed with the **same upload key** as the current Play version. Either:
   - send the key to Claude to sign the AAB, or
   - add these repository **secrets** so GitHub signs it automatically: `UPLOAD_KEYSTORE_BASE64`, `UPLOAD_STORE_PASSWORD`, `UPLOAD_KEY_ALIAS`, `UPLOAD_KEY_PASSWORD` (already set in this repo).
3. Every push to `main` or `release/**` builds `app-release.aab` and `app-release.apk` into the **`out-aab`** branch, with `BUILD_INFO.txt` showing whether it used the upload key and which API URL.
4. **Test on your phone first** using the internal testing track:
   - [ ] Log in with a free account. Live signals show locked; tapping one opens the paywall.
   - [ ] History, Results, Markets and Tools work normally.
   - [ ] Buy Yearly with a licence-tester account. Signals unlock within seconds, and Profile shows "Premium · Yearly".
   - [ ] Uninstall, reinstall, log in: still Premium. On a second account: "Restore purchase" says it belongs to another account.
   - [ ] Cancel in Play → after the test period ends, signals lock again.
5. **Production**: Play Console → **Production → Create new release** → upload the same AAB (or promote it from internal testing).

   Release notes:
   ```
   <en-US>
   NEW: Forex Signals Live Premium. Unlock live entry, stop loss and take profit levels with a 7-day free trial, then $5/month or $36/year.
   Improved signal strategy on 4-hour charts: fewer, higher-quality signals.
   Results, reports, prices and tools stay free.
   </en-US>
   ```
6. **Store listing**: change wording like "free forex signals" to "free trial", and say that live signal levels need a subscription. Google rejects listings that call a paid feature free.
7. **App content → Sign-in details**: give the reviewer an account listed in `premium_emails` so they can see the full app.
8. **Send for review.**
9. **Launch day**: when Play shows the update as live, edit `api/src/config.php` and set `'premium_enforced' => true,`. That one line starts locking live signals for non-subscribers and tells old app versions to update. Until then everyone keeps full access, so nobody is told to update before the update exists.

---

## How users who don't update are handled

| Situation | What they see |
|---|---|
| *(all rows below apply after launch day, when `premium_enforced` is true)* | |
| Old app, opens **Active signals** | The server refuses: *"Forex Signals Live has a new version. Please update the app from Google Play to see live signals."* No levels are sent. |
| Old app, **History / Results / Reports / Markets** | Work as before, so they still see your track record. |
| Old app, **push alerts** | New-signal alerts become teasers ("🔒 New BUY signal on EUR/USD — subscribe to see the entry…"). Result alerts (TP/SL hit) are unchanged. |
| Old app, **emails** | New-signal emails show pair, direction and strength only, with a link to unlock. |
| Anyone tampering with the app | Levels are removed **on the server** for non-subscribers, so there is nothing hidden on the phone to unlock. |

You can also make the update mandatory in Play Console (**Release → In-app updates priority**). That's not needed, though, because the server already enforces the lock.

## Notes
- **Firebase push isn't configured in this repo** (there's no `google-services.json`), so alerts come from the app's built-in background checker. To get instant FCM pushes, add Firebase to the Android app; the server's push code is ready.
- Deleting an account in the app does not cancel a Google Play subscription. The app tells users this.
- The strategy backtest is in `backend/STRATEGY_RESULTS.md`.
