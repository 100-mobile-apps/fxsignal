-- Forex Signals Live: Premium subscriptions update (Nov 2026).
-- Run ONCE on the existing live database: cPanel → phpMyAdmin → select the database → Import this file.
-- Safe for existing users: it only adds columns and tables; no data is changed or removed.

ALTER TABLE users
  ADD COLUMN premium_until DATETIME NULL AFTER watchlist,
  ADD COLUMN premium_plan VARCHAR(40) NULL AFTER premium_until;
-- Google Play subscriptions (one row per purchase token; token stored encrypted)
CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  plan VARCHAR(40) NULL,
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
