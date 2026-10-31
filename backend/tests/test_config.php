<?php
return [
  'app_name' => 'FX Signals Test',
  'db' => ['dsn' => 'sqlite:' . __DIR__ . '/test.db'],
  'jwt_secret' => str_repeat('x', 48),
  'pairs' => ['EUR/USD','GBP/USD','USD/JPY','AUD/USD','USD/CAD','USD/CHF','NZD/USD','XAU/USD'],
  'timeframe' => '1h', 'min_confidence' => 65, 'signal_expiry_hours' => 72, 'cooldown_hours' => 4,
  'fcm' => ['project_id' => '', 'service_account_file' => ''],
  'mail_disabled' => true, 'debug' => true,
];
