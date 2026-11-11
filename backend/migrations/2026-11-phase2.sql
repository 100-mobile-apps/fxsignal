-- Forex Signals Live: Phase 2 update (admin settings, automated emails, trading bot).
-- Run ONCE after 2026-11-premium.sql: cPanel → phpMyAdmin → select the database → Import this file.
-- Only adds columns and tables; no data is changed or removed.

ALTER TABLE users
  ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at,
  ADD COLUMN email_marketing TINYINT(1) NOT NULL DEFAULT 1 AFTER email_monthly;

ALTER TABLE subscriptions
  ADD COLUMN phase VARCHAR(10) NULL AFTER plan;

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
