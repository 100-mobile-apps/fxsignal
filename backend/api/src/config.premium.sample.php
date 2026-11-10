<?php
// Forex Signals Live: Premium + strategy v2 settings (no passwords or keys in this file).
// Loaded after config.php and overrides it.
return [
    // Strategy v2 runs on 4-hour candles.
    'timeframe' => '4h',
    'signal_expiry_hours' => 120,

    // LAUNCH SWITCH: false = nothing locked, everyone keeps full signals.
    // Set to true the day version 1.1.0 is live on Google Play.
    'premium_enforced' => false,

    'play' => [
        'package_name' => 'com.forexsignalslive.forex_signals',
        'service_account_file' => __DIR__ . '/play-service-account.json',
        'products' => ['fx_premium_monthly', 'fx_premium_yearly'],
        'rtdn_secret' => '', // optional: real-time notifications (see PUBLISH_PREMIUM_UPDATE.md)
    ],
    // Accounts that get Premium free (owner, testers, Google Play reviewer).
    'premium_emails' => ['you@example.com'],
    'min_app_build' => 5,
];
