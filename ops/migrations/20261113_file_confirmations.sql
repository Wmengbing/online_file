-- Run once on an existing database before deploying the confirmation feature.
-- CREATE TABLE IF NOT EXISTS makes this migration safe to rerun.
CREATE TABLE IF NOT EXISTS `file_confirmations` (
  `file_id` int unsigned NOT NULL,
  `confirmed_by` int unsigned NOT NULL,
  `confirmed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`file_id`),
  KEY `idx_confirmed_by` (`confirmed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
