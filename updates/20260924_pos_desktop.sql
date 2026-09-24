-- Dapur Adena POS Desktop backend support.
CREATE TABLE IF NOT EXISTS pos_api_sessions(
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  device_id VARCHAR(120) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_pos_token_hash(token_hash),
  KEY idx_pos_user_device(user_id,device_id),
  KEY idx_pos_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @db=DATABASE();
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='kitchen_sales_headers' AND COLUMN_NAME='pos_uuid')=0,'ALTER TABLE kitchen_sales_headers ADD COLUMN pos_uuid VARCHAR(40) NULL AFTER remote_response','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='kitchen_sales_headers' AND COLUMN_NAME='pos_device_id')=0,'ALTER TABLE kitchen_sales_headers ADD COLUMN pos_device_id VARCHAR(120) NULL AFTER pos_uuid','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='kitchen_sales_headers' AND COLUMN_NAME='payment_method')=0,'ALTER TABLE kitchen_sales_headers ADD COLUMN payment_method VARCHAR(30) NULL AFTER pos_device_id','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='kitchen_sales_headers' AND COLUMN_NAME='paid_amount')=0,'ALTER TABLE kitchen_sales_headers ADD COLUMN paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER payment_method','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='kitchen_sales_headers' AND COLUMN_NAME='change_amount')=0,'ALTER TABLE kitchen_sales_headers ADD COLUMN change_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER paid_amount','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='kitchen_sales_headers' AND COLUMN_NAME='pos_created_at')=0,'ALTER TABLE kitchen_sales_headers ADD COLUMN pos_created_at DATETIME NULL AFTER change_amount','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='kitchen_sales_headers' AND INDEX_NAME='uq_kitchen_sales_pos_uuid')=0,'ALTER TABLE kitchen_sales_headers ADD UNIQUE KEY uq_kitchen_sales_pos_uuid(pos_uuid)','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
