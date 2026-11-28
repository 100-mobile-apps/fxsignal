<?php
// Copy this file to config.php (same folder) and fill in your values.
return [
    'app_name' => 'Forex Signals Live',

    // cPanel → MySQL Databases: create a database + user, add the user to the database (ALL PRIVILEGES)
    'db' => [
        'host' => 'localhost',
        'name' => 'cpaneluser_fxsignals',
        'user' => 'cpaneluser_fxuser',
        'pass' => 'your-database-password',
    ],

    // Long random string (40+ characters). Changing it logs everyone out.
    'jwt_secret' => 'CHANGE_ME_to_a_long_random_string_of_40_plus_characters',

    // Obtain your key from https://twelvedata.com; verify current plan limits and symbol access.
    'twelvedata_key' => '',
    'requests_per_minute' => 8,

    // Instruments and timeframe the engine watches.
    // Ten pairs every 20 minutes request roughly 720 series/day (Twelve Data free plan: 800/day).
    'pairs' => ['EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'USD/CHF', 'NZD/USD', 'XAU/USD', 'BTC/USD', 'ETH/USD'],
    // Premium-only markets, and the strength above which other markets need Premium.
    'premium_markets' => ['XAU/USD', 'BTC/USD', 'ETH/USD'],
    'free_max_strength' => 70,
    // Strategy v2 is built and tested for 4-hour candles.
    'timeframe' => '4h',

    // Signal rules
    'min_confidence' => 65,       // minimum strength (0–100) to publish a signal
    'max_entry_drift_atr' => 0.5, // reject a live entry >0.5 ATR from the confirmed closed candle
    'signal_expiry_hours' => 120, // open signals are closed at market after this (5 days on 4h)
    'cooldown_hours' => 4,        // wait after a signal closes before a new one on that pair

    // Push notifications (optional). Firebase console → Project settings → Service accounts →
    // Generate new private key. Upload the JSON next to this file.
    'fcm' => [
        'project_id' => '',
        'service_account_file' => __DIR__ . '/firebase-service-account.json',
    ],

    // Premium subscriptions (Google Play Billing). See PUBLISH_PREMIUM_UPDATE.md.
    // Play Console → Users and permissions → invite the service account's email with
    // "View financial data" + "Manage orders and subscriptions". Upload its JSON key next to this file.
    'play' => [
        'package_name' => 'com.forexsignalslive.forex_signals',
        'service_account_file' => __DIR__ . '/play-service-account.json',
        'products' => ['fx_premium_monthly', 'fx_premium_yearly'],
        // Long random string; the Pub/Sub push URL is https://yourdomain.com/api/billing/rtdn?secret=THIS_VALUE
        'rtdn_secret' => '',
    ],
    // false = everyone keeps full access (use while the paywall app update is in Google review).
    // Set to true the day version 1.1.0 is live on Google Play to start locking live signals.
    'premium_enforced' => false,
    // Accounts that get Premium free (you, testers, the Google Play reviewer account).
    'premium_emails' => [],
    // App builds older than this are asked to update before they can open live signals.
    'min_app_build' => 5,

    // Public URL of this API (used for unsubscribe links in emails)
    'base_url' => 'https://yourdomain.com/api',

    // Daily/weekly/monthly reports are grouped in this timezone
    'report_timezone' => 'Africa/Lagos',

    // Email: create an account in cPanel → Email Accounts (e.g. alerts@yourdomain.com)
    // and use its SMTP settings (cPanel → Email Accounts → Connect Devices).
    'mail' => [
        'transport' => 'smtp',          // 'smtp' (recommended) or 'mail'
        'host' => 'mail.yourdomain.com',
        'port' => 465,
        'secure' => 'ssl',              // 465 = 'ssl', 587 = 'tls'
        'user' => 'alerts@yourdomain.com',
        'pass' => 'email-account-password',
        'from' => 'alerts@yourdomain.com',
        'from_name' => 'Forex Signals Live',
        'max_per_run' => 40,            // emails per cron run; keep under your host's hourly limit ÷ 3
    ],

    // Developer links shown in the app (WhatsApp chat, Buy Me a Coffee, hire the developer).
    // Set 'coffee' => false if Google Play asks you to remove the tip link; the app picks it up on next launch.
    'promo' => ['coffee' => true, 'whatsapp' => true, 'hire' => true],
    'developer' => [
        'name' => 'Swiftmynd Technologies',
        'whatsapp' => 'https://wa.me/2347011401387?text=Hi%20Abdullahi%2C%20I%20use%20Forex%20Signals%20Live%20and%20I%27d%20like%20to%20talk%20about%20building%20an%20app%20or%20website.',
        'coffee' => 'https://buymeacoffee.com/swalafblogM',
    ],

    'cors_origin' => '*',
    'debug' => false,
];
