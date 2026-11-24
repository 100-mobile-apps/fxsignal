-- FX Signals database (MySQL / MariaDB). Import in cPanel → phpMyAdmin → Import.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  token_version INT NOT NULL DEFAULT 0,
  email_verified_at DATETIME NULL,
  notify_signals TINYINT(1) NOT NULL DEFAULT 1,
  notify_results TINYINT(1) NOT NULL DEFAULT 1,
  notify_reports TINYINT(1) NOT NULL DEFAULT 1,
  notify_pairs TEXT NULL,
  email_signals TINYINT(1) NOT NULL DEFAULT 1,
  email_results TINYINT(1) NOT NULL DEFAULT 1,
  email_daily TINYINT(1) NOT NULL DEFAULT 0,
  email_weekly TINYINT(1) NOT NULL DEFAULT 1,
  email_monthly TINYINT(1) NOT NULL DEFAULT 1,
  email_marketing TINYINT(1) NOT NULL DEFAULT 1,
  watchlist TEXT NULL,
  signal_pairs TEXT NULL,
  referred_by INT UNSIGNED NULL,
  referred_at DATETIME NULL,
  blocked_at DATETIME NULL,
  premium_until DATETIME NULL,
  premium_plan VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  last_seen_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-time 6-digit codes for email verification and password reset
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(10) NOT NULL DEFAULT 'reset',
  code_hash VARCHAR(255) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS signals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pair VARCHAR(10) NOT NULL,
  timeframe VARCHAR(10) NOT NULL,
  direction VARCHAR(4) NOT NULL,
  entry DOUBLE NOT NULL,
  stop_loss DOUBLE NOT NULL,
  tp1 DOUBLE NOT NULL,
  tp2 DOUBLE NOT NULL,
  confidence INT NOT NULL,
  reasons TEXT NULL,
  candles MEDIUMTEXT NULL,
  candle_t INT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'running',
  result VARCHAR(10) NULL,
  tp1_hit_at DATETIME NULL,
  closed_at DATETIME NULL,
  close_price DOUBLE NULL,
  pips DOUBLE NULL,
  current_price DOUBLE NULL,
  live_pips DOUBLE NULL,
  opened_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (status, opened_at),
  INDEX (pair, status),
  INDEX (closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outgoing emails, sent in batches by the cron job (respects cPanel hourly limits)
CREATE TABLE IF NOT EXISTS email_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  html MEDIUMTEXT NOT NULL,
  text_body MEDIUMTEXT NOT NULL,
  unsubscribe_url VARCHAR(500) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  INDEX (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prices (
  symbol VARCHAR(10) PRIMARY KEY,
  price DOUBLE NOT NULL,
  change_pct DOUBLE NOT NULL,
  high DOUBLE NOT NULL,
  low DOUBLE NOT NULL,
  spark TEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  k VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (k, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meta (
  k VARCHAR(64) PRIMARY KEY,
  v MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Google Play subscriptions (one row per purchase token; token stored encrypted)
CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  plan VARCHAR(40) NULL,
  phase VARCHAR(10) NULL,
  purchase_token TEXT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  state VARCHAR(40) NOT NULL,
  expires_at DATETIME NULL,
  auto_renewing TINYINT(1) NOT NULL DEFAULT 0,
  order_id VARCHAR(80) NULL,
  test_purchase TINYINT(1) NOT NULL DEFAULT 0,
  last_checked_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (user_id),
  INDEX (state, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Push tokens of logged-in devices (full signal alerts go only to premium users' devices)
CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token VARCHAR(400) NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings edited on /admin (override the config files)
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Automated emails already sent (dedupe and weekly cap)
CREATE TABLE IF NOT EXISTS email_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  ref VARCHAR(40) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX (user_id, kind),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User actions used to target emails (e.g. tapping a locked signal)
CREATE TABLE IF NOT EXISTS user_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (kind, created_at),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS signal_unlocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  signal_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (user_id, signal_id),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every payment from any source (Google Play renewals, Paystack on the website, manual)
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  source VARCHAR(10) NOT NULL,
  ref VARCHAR(120) NOT NULL,
  plan VARCHAR(20) NOT NULL,
  amount DOUBLE NOT NULL,
  currency VARCHAR(3) NOT NULL,
  amount_usd DOUBLE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'paid',
  test TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE (source, ref),
  INDEX (user_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Paystack subscriptions bought on the website
CREATE TABLE IF NOT EXISTS web_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  plan VARCHAR(20) NOT NULL,
  customer_code VARCHAR(80) NULL,
  subscription_code VARCHAR(80) NULL,
  email_token VARCHAR(80) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  next_payment_at DATETIME NULL,
  paid_until DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (user_id),
  INDEX (customer_code),
  INDEX (subscription_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Affiliates (one per user who joined the programme)
CREATE TABLE IF NOT EXISTS affiliates (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  rate DOUBLE NULL,
  broker_name VARCHAR(60) NULL,
  broker_url VARCHAR(300) NULL,
  broker_status VARCHAR(10) NULL,
  payout_method VARCHAR(20) NULL,
  payout_details TEXT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Referral link visits and broker-button clicks (IP stored only as a short hash)
CREATE TABLE IF NOT EXISTS referral_clicks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT UNSIGNED NOT NULL,
  kind VARCHAR(10) NOT NULL,
  ip_hash CHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (affiliate_id, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Commission per payment by a referred user
CREATE TABLE IF NOT EXISTS commissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT UNSIGNED NOT NULL,
  referred_user_id INT UNSIGNED NOT NULL,
  payment_id INT UNSIGNED NOT NULL UNIQUE,
  rate DOUBLE NOT NULL,
  amount_usd DOUBLE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  available_at DATETIME NOT NULL,
  payout_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX (affiliate_id, status),
  INDEX (payout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payout requests
CREATE TABLE IF NOT EXISTS payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT UNSIGNED NOT NULL,
  amount_usd DOUBLE NOT NULL,
  method VARCHAR(20) NOT NULL,
  details TEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'requested',
  reference VARCHAR(120) NULL,
  admin_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  INDEX (affiliate_id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
