-- Run ONCE after 2026-11-phase2.sql. Only adds a column and a table.
-- Phase 2b: free unlock every 2 weeks, and the markets each user chose to see
ALTER TABLE users ADD COLUMN signal_pairs TEXT NULL AFTER watchlist;
CREATE TABLE IF NOT EXISTS signal_unlocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  signal_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY user_signal (user_id, signal_id),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
