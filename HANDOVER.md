# Handover: Forex Signals Live (fxsignal)

Repo: github.com/100-mobile-apps/fxsignal (Flutter app in `app/`, PHP/MySQL backend in `backend/`, MT5 bot in `ea/`).
Live API: https://forexsignal.bulksend.top (cPanel host c8.my-control-panel.com:2083, docroot /home/globacom/forexsignal.bulksend.top, code in `src/`).
NOTE: the local commits below were never pushed (GitHub app not installed on the org for the previous session). Latest local commit: ee0fb57.

## Owner preferences (strict)
- Show numbers as dollars and pips only. Never use "R" (risk multiples).
- Gold is the default market; all markets selectable by everyone. Risk default 1%; 30% selectable and always shown with its drawdown.
- Two-trade method: half position to TP1, half to TP2. After TP1, move the TP2 stop to entry; at halfway to TP2, move it to TP1.
- Honest tracking only. Ask the owner for explicit confirmation before any production deploy.
- No more long multi-year backtests.
- Goal: quick income, everything in-app, cheap upsell later (gold paid tier via Paystack later; Telegram auto-posting later).

## What is live on the server now (deployed 2026-11-02 and 2026-11-04)
- Honest tracker (two half trades, lock rule), gold default, email verification reminders.
- Five strategies on 1h candles, one running signal per (pair, strategy): classic (the original engine, only one with a live record), steady, adaptive, high_reward, win90 (opt-in only). Everyone defaults to classic until another has 20 live results (`MarketRank::bestStrategy`).
- Best-markets ranking (last 14 days, min 8 trades, top 3 become defaults, fallback gold) and hourly watch-only markets (config `watch_pairs`, signals saved with published=0).
- Lists, stats and reports are scoped to the viewer's strategy (`Strategies::scopeSql`).
- Endpoints: GET /results, GET /strategies, PUT /me/preferences, GET/POST /me/ea-key, GET /ea/signals (header X-EA-Key). Bot file served at /downloads/FxSignalBot.mq5.
- DB changes applied: signals.strategy, signals.published, users.strategy/account_size/risk_pct/ea_key. Backups: tables signals_backup_20261102 and *_20261102b, users_backup_20261102b; file backups `*.bak-20261102`, `.bak-20261102b`, `.bak-20261102c` next to the live files.
- Deploy method used: upload a tarball with Fileman API from a logged-in cPanel session, then run a PHP deploy script in cPanel Terminal. The script checks sha256 of live files first, backs up, migrates, swaps files. (Deploy scripts were in the previous session's scratchpad; they are not in the repo.)

## What is NOT done / needs doing
1. **Flutter app is uncompiled.** All new app code (Your money dashboard, strategy picker, dollar figures on signal cards/detail, strategy-aware push topics, Auto-trade bot screen) was only syntax-checked with a parser. Run `cd app && flutter pub get && flutter analyze && flutter test`, fix errors, then build a signed AAB with a build number higher than the one on Play Store:
   `flutter build appbundle --dart-define=API_URL=https://forexsignal.bulksend.top`
   Needs Flutter >= 3.38.4 (3.41.7 was used before). Use your own signing key (see app/README.md).
2. Remaining app tasks: banners for push-notifications-off and email-not-verified; check app/test files compile with the changed models (they only use unchanged signatures as far as grep showed).
3. **MT5 bot `ea/FxSignalBot.mq5` is uncompiled/untested.** Compile in MetaEditor, test on a demo hedging account. Check: JSON parsing, lot sizing, SL moves, closing legs when the server closes a signal.
4. Server-side: backtests are not in /results (live results only). New strategies (steady, adaptive, high_reward, win90) are translations of earlier research code and have NOT been validated live or re-backtested.
5. Security cleanup with owner permission: move backup.zip, backend.zip, fxsignals-cpanel*.zip, schema.sql, tests/ out of the docroot.
6. Update Play Store listing claims to match the honest results.
7. Later: Telegram auto-posting, Paystack premium.

## Key facts
- Real live results (202 closed trades 9 Oct to 2 Nov, $20k account, after costs): all markets at 1% lost about $6,361. Only gold and USD/CAD were positive. Gold + USD/CAD together +$2,922 at 1% (+$199,759 at 30% with a 64% max drop). Best-3-weekly picks at 30% on $20k gave about +$137,001 with a 64% drop. These are pooled tiny samples; do not oversell.
- Small accounts: minimum position is 2 x 0.01 lot, so a $100 account on gold risks more than 30% on wide stops. The app warns about this.
- Costs: FX 1.5 pips, gold 3 pips, BTC 0.05%, ETH 0.07%. Twelve Data free plan 800 req/day (about 720 used).
- Tests: `php backend/tests/strategies_test.php` (22 checks), `php backend/tests/ea_test.php` (10 checks), `php backend/tests/backtest_test.php`. `tests/e2e.php` needs MySQL. Running tests dirties the tracked `tests/test.db`; restore with `git checkout backend/tests/test.db`.
- cPanel login sessions expire and are IP-bound; the owner must log in. Never type or store the owner's passwords.
