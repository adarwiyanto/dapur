-- DAPUR ADENA - KEUANGAN DAN FONDASI SINKRONISASI
-- Aman untuk database berjalan. Tidak mengubah tabel pembelian/produksi lama.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS expense_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  category_code VARCHAR(80) NOT NULL,
  category_name VARCHAR(160) NOT NULL,
  group_name VARCHAR(120) NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  requires_approval TINYINT(1) NOT NULL DEFAULT 0,
  requires_evidence TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dapur_expense_category_code (category_code),
  UNIQUE KEY uq_dapur_expense_category_uuid (record_uuid),
  KEY idx_dapur_expense_category_active (is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  expense_no VARCHAR(80) NOT NULL,
  expense_date DATE NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  category_name_snapshot VARCHAR(160) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(190) NULL,
  payment_method VARCHAR(80) NULL,
  reference_no VARCHAR(120) NULL,
  evidence_reference VARCHAR(255) NULL,
  status ENUM('draft','submitted','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'paid',
  due_date DATE NULL,
  approved_by BIGINT NULL,
  approved_at DATETIME NULL,
  paid_by BIGINT NULL,
  paid_at DATETIME NULL,
  created_by BIGINT NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dapur_expenses_no (expense_no),
  UNIQUE KEY uq_dapur_expenses_uuid (record_uuid),
  KEY idx_dapur_expenses_date_status (expense_date,status),
  KEY idx_dapur_expenses_category (category_id,expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_uuid CHAR(36) NULL,
  request_no VARCHAR(80) NOT NULL,
  request_date DATE NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  category_name_snapshot VARCHAR(160) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(190) NULL,
  due_date DATE NULL,
  reference_no VARCHAR(120) NULL,
  evidence_reference VARCHAR(255) NULL,
  status ENUM('draft','submitted','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  requested_by BIGINT NULL,
  approved_by BIGINT NULL,
  approved_at DATETIME NULL,
  paid_by BIGINT NULL,
  paid_at DATETIME NULL,
  linked_expense_id BIGINT UNSIGNED NULL,
  rejection_reason TEXT NULL,
  version_no INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dapur_payment_requests_no (request_no),
  UNIQUE KEY uq_dapur_payment_requests_uuid (record_uuid),
  KEY idx_dapur_payment_requests_status (request_date,status),
  KEY idx_dapur_payment_requests_due (due_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  action_key VARCHAR(50) NOT NULL,
  payload_json LONGTEXT NULL,
  acted_by BIGINT NULL,
  acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dapur_finance_audit_entity (entity_type,entity_id,acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(120) NOT NULL,
  operation VARCHAR(30) NOT NULL,
  entity_version INT NOT NULL DEFAULT 1,
  payload_json LONGTEXT NOT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sync_status ENUM('pending','processing','synced','failed') NOT NULL DEFAULT 'pending',
  retry_count INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  synced_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dapur_sync_outbox_event (event_uuid),
  KEY idx_dapur_sync_outbox_status (sync_status,available_at),
  KEY idx_dapur_sync_outbox_entity (entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO expense_categories(record_uuid,category_code,category_name,group_name,sort_order,is_active)
VALUES
(UUID(),'UTIL-ELECTRICITY','Listrik','Utilitas',10,1),
(UUID(),'UTIL-WATER','Air','Utilitas',20,1),
(UUID(),'UTIL-GAS','Gas Produksi','Utilitas',30,1),
(UUID(),'MAINTENANCE-EQUIPMENT','Perawatan Mesin / Alat','Operasional',40,1),
(UUID(),'VEHICLE','Kendaraan Distribusi / Bahan Bakar','Operasional',50,1),
(UUID(),'CLEANING','Kebersihan','Operasional',60,1),
(UUID(),'ADMIN','Administrasi','Administrasi',70,1),
(UUID(),'OTHER','Lain-lain','Lainnya',999,1);
