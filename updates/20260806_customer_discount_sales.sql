-- Dapur Adena patch 20260806a
CREATE TABLE IF NOT EXISTS customers(
 id INT AUTO_INCREMENT PRIMARY KEY,
 customer_code VARCHAR(60) NULL,
 customer_name VARCHAR(160) NOT NULL,
 phone VARCHAR(60) NULL,
 address TEXT NULL,
 notes TEXT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 UNIQUE KEY uq_customer_code(customer_code),
 KEY idx_customer_active_name(is_active,customer_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE kitchen_sales_headers ADD COLUMN customer_id INT NULL AFTER store_id;
ALTER TABLE kitchen_sales_items ADD COLUMN original_price DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER transfer_price;
ALTER TABLE kitchen_sales_items ADD COLUMN discount_type VARCHAR(20) NOT NULL DEFAULT 'none' AFTER original_price;
ALTER TABLE kitchen_sales_items ADD COLUMN discount_value DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER discount_type;
ALTER TABLE kitchen_sales_items ADD COLUMN discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount_value;
