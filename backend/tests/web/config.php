<?php
// Test config for the website/admin/affiliate tests (MariaDB on 127.0.0.1, database fxweb).
return [
  'app_name' => 'Forex Signals Live',
  'db' => ['host' => '127.0.0.1', 'name' => getenv('FX_DB') ?: 'fxweb', 'user' => 'fxtest', 'pass' => 'fxtest-pass'],
  'jwt_secret' => str_repeat('k', 48),
  'pairs' => ['EUR/USD','GBP/USD','USD/JPY','AUD/USD','USD/CAD','USD/CHF','NZD/USD','XAU/USD','BTC/USD','ETH/USD'],
  'timeframe' => '4h', 'min_confidence' => 65, 'signal_expiry_hours' => 120, 'cooldown_hours' => 4,
  'fcm' => ['project_id' => '', 'service_account_file' => ''],
  'base_url' => 'http://127.0.0.1:8093/api',
  'site_url' => 'http://127.0.0.1:8093',
  'report_timezone' => 'Africa/Lagos',
  'mail' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 2526, 'secure' => '', 'user' => 'alerts@example.com',
             'pass' => 'x', 'from' => 'alerts@example.com', 'from_name' => 'Forex Signals Live', 'max_per_run' => 200],
  'admin_emails' => ['admin@example.com'],
  'premium_enforced' => true,
  'premium_markets' => ['XAU/USD', 'BTC/USD', 'ETH/USD'],
  'free_max_strength' => 70,
  'paystack' => ['secret_key' => 'sk_test_fake_secret_for_tests'],
  'broker_default' => ['name' => 'Default Broker', 'url' => 'https://broker.example.com/?ib=owner'],
  'debug' => true,
];
