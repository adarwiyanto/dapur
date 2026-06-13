-- Patch Dapur Adena: import produk dari toko + progress bar
-- Aman dijalankan berulang. Runtime patch tidak bergantung pada unique key ini.
-- Index non-unique membantu pencarian produk sumber tanpa mengubah data lama.
SET @idx_exists := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'finished_products'
    AND index_name = 'idx_finished_source_lookup'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE finished_products ADD INDEX idx_finished_source_lookup (source_store_id, source_product_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
