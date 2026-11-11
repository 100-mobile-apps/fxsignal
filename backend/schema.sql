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
