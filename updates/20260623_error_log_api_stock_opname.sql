-- Patch 20260623
-- Error Log owner-only, API test transfer dry-run, dan Stok Opname Dapur.

CREATE TABLE IF NOT EXISTS stock_opname_headers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  opname_no VARCHAR(60) UNIQUE NOT NULL,
  opname_date DATE NOT NULL,
  item_type VARCHAR(20) NOT NULL,
  total_items INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_opname_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  opname_id BIGINT NOT NULL,
  item_type VARCHAR(20) NOT NULL,
  item_id INT NOT NULL,
  item_name VARCHAR(180) NULL,
  system_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  physical_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  difference_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  unit VARCHAR(40) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_opname_id(opname_id),
  KEY idx_item(item_type,item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO permissions(permission_key, permission_name) VALUES
('stock_opname', 'Stok Opname'),
('error_log', 'Error Log');

-- Akses stok opname hanya untuk Owner dan Admin Dapur.
-- Owner tetap otomatis full access dari kode aplikasi.
INSERT IGNORE INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key = 'stock_opname'
WHERE r.role_key = 'admin_dapur';
