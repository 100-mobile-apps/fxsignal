<?php
return [
  'app_name' => 'Forex Signals Live',
  'db' => ['host' => '127.0.0.1', 'name' => 'fxsignals_test', 'user' => 'fxtest', 'pass' => 'fxtest-pass'],
  'jwt_secret' => str_repeat('k', 48),
  'pairs' => ['EUR/USD','GBP/USD','USD/JPY','AUD/USD','USD/CAD','USD/CHF','NZD/USD','XAU/USD'],
  'timeframe' => '1h', 'min_confidence' => 65, 'signal_expiry_hours' => 72, 'cooldown_hours' => 4,
  'fcm' => ['project_id' => '', 'service_account_file' => ''],
  'base_url' => 'http://127.0.0.1:8091',
  'report_timezone' => 'Africa/Lagos',
  'mail' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 2525, 'secure' => '', 'user' => 'alerts@example.com',
             'pass' => 'x', 'from' => 'alerts@example.com', 'from_name' => 'Forex Signals Live', 'max_per_run' => 200],
  'debug' => true,
];
