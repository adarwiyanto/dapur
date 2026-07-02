SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_pairing_requests' AND COLUMN_NAME='notification_dismissed_at');
SET @sql := IF(@has_col=0, 'ALTER TABLE api_pairing_requests ADD COLUMN notification_dismissed_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_pairing_requests' AND COLUMN_NAME='notification_dismissed_by');
SET @sql := IF(@has_col=0, 'ALTER TABLE api_pairing_requests ADD COLUMN notification_dismissed_by BIGINT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO permissions(permission_key,permission_name) VALUES ('api','API & Integrasi');
