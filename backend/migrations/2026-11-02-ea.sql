-- Auto-trade bot: personal read-only key per user.
ALTER TABLE users ADD COLUMN ea_key CHAR(32) NULL AFTER risk_pct;
ALTER TABLE users ADD INDEX idx_users_ea_key (ea_key);
