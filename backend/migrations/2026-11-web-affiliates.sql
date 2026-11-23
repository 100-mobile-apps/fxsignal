-- Forex Signals Live: website, Paystack payments and affiliate programme (Nov 2026).
-- Run ONCE after 2026-11-phase2b.sql: cPanel → phpMyAdmin → select the database → Import this file.
-- Only adds columns and tables; no data is changed or removed.

ALTER TABLE users
  ADD COLUMN referred_by INT UNSIGNED NULL AFTER signal_pairs,
  ADD COLUMN referred_at DATETIME NULL AFTER referred_by,
  ADD COLUMN blocked_at DATETIME NULL AFTER referred_at,
  ADD INDEX (referred_by);

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
