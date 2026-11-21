-- Strategies, best-markets ranking and money preferences. Run once in phpMyAdmin BEFORE uploading the new files.
ALTER TABLE signals ADD COLUMN strategy VARCHAR(16) NOT NULL DEFAULT 'classic' AFTER pair;
ALTER TABLE signals ADD COLUMN published TINYINT(1) NOT NULL DEFAULT 1 AFTER strategy;
ALTER TABLE users ADD COLUMN strategy VARCHAR(16) NULL AFTER watchlist;
ALTER TABLE users ADD COLUMN account_size DOUBLE NULL AFTER strategy;
ALTER TABLE users ADD COLUMN risk_pct DOUBLE NULL AFTER account_size;
