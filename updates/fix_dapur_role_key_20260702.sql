-- Fix lanjutan untuk error SQL quote pada patch 20260702. Aman dijalankan berulang.
SET @need_col := (SELECT COUNT(*)=0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='role_key');
SET @ddl := IF(@need_col, 'ALTER TABLE employees ADD COLUMN role_key VARCHAR(50) NOT NULL DEFAULT ''pegawai_dapur'' AFTER phone', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE employees SET role_key='pegawai_dapur' WHERE role_key IS NULL OR role_key='';

UPDATE employees e
JOIN users u ON LOWER(TRIM(u.name))=LOWER(TRIM(e.employee_name))
JOIN roles r ON r.id=u.role_id
SET e.role_key = CASE
  WHEN r.role_key='owner' THEN 'owner'
  WHEN r.role_key='admin_dapur' THEN 'admin_dapur'
  WHEN r.role_key='manager_dapur' THEN 'manager_dapur'
  ELSE 'pegawai_dapur'
END;
