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
    // Eight pairs every 20 minutes request roughly 576 series/day before retries or other usage.
    'pairs' => ['EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'USD/CHF', 'NZD/USD', 'XAU/USD'],
    // Markets new users follow (watchlist, push and emails) until they choose their own.
    'default_pairs' => ['XAU/USD'],
    // Best recent markets (last 14 days, after costs) become the default for people who never chose; falls back to default_pairs.
    'auto_default_pairs' => true,
    // Markets that are only watched: their signals are recorded silently (never sent) so they can prove themselves first.
    // Each costs about 24 data requests a day. Eight live pairs use about 576 of the 800 free requests a day.
    'watch_pairs' => ['EUR/GBP', 'EUR/JPY', 'GBP/JPY', 'AUD/JPY', 'BTC/USD', 'ETH/USD'],
    // Strategies that run (omit for all). Order of preference before any has 20 live results:
    // 'strategies' => ['steady', 'adaptive', 'high_reward', 'classic', 'win90'],
    'strategy_priors' => ['steady', 'adaptive', 'classic', 'high_reward'],
    // Optional: only these pairs get NEW signals; the rest keep prices and running signals tracked.
    // 'publish_pairs' => ['XAU/USD'],
    'timeframe' => '1h',

    // Signal rules
    'min_confidence' => 65,       // minimum strength (0–100) to publish a signal
    'max_entry_drift_atr' => 0.5, // reject a live entry >0.5 ATR from the confirmed closed candle
    'signal_expiry_hours' => 72,  // open signals are closed at market after this
    'cooldown_hours' => 4,        // wait after a signal closes before a new one on that pair

    // Push notifications (optional). Firebase console → Project settings → Service accounts →
    // Generate new private key. Upload the JSON next to this file.
    'fcm' => [
        'project_id' => '',
        'service_account_file' => __DIR__ . '/firebase-service-account.json',
    ],

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
