-- Patch 20260702 final: UI role normalization + employee role for Back Office. Aman dijalankan berulang.

INSERT IGNORE INTO roles(role_key, role_name) VALUES ('owner','Owner'),('admin_dapur','Admin Dapur'),('manager_dapur','Manajer Dapur'),('pegawai_dapur','Pegawai Dapur');
UPDATE roles SET role_name='Owner' WHERE role_key='owner';
UPDATE roles SET role_name='Admin Dapur' WHERE role_key='admin_dapur';
UPDATE roles SET role_name='Manajer Dapur' WHERE role_key IN ('manager_dapur','kepala_dapur');
UPDATE roles SET role_name='Pegawai Dapur' WHERE role_key IN ('pegawai_dapur','kasir_dapur','viewer');
UPDATE users u JOIN roles rold ON rold.id=u.role_id AND rold.role_key='superadmin' JOIN roles rnew ON rnew.role_key='owner' SET u.role_id=rnew.id;
UPDATE users u JOIN roles rold ON rold.id=u.role_id AND rold.role_key='kepala_dapur' JOIN roles rnew ON rnew.role_key='manager_dapur' SET u.role_id=rnew.id;
UPDATE users u JOIN roles rold ON rold.id=u.role_id AND rold.role_key IN ('kasir_dapur','viewer') JOIN roles rnew ON rnew.role_key='pegawai_dapur' SET u.role_id=rnew.id;
DELETE rp FROM role_permissions rp LEFT JOIN roles r ON r.id=rp.role_id WHERE r.role_key IN ('superadmin','kepala_dapur','kasir_dapur','viewer');
DELETE FROM roles WHERE role_key IN ('superadmin','kepala_dapur','kasir_dapur','viewer');
SET @need_col := (SELECT COUNT(*)=0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='role_key');
SET @ddl := IF(@need_col, 'ALTER TABLE employees ADD COLUMN role_key VARCHAR(50) NOT NULL DEFAULT 'pegawai_dapur' AFTER phone', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE employees SET role_key='pegawai_dapur' WHERE role_key IS NULL OR role_key='';
UPDATE employees e JOIN users u ON LOWER(TRIM(u.name))=LOWER(TRIM(e.employee_name)) JOIN roles r ON r.id=u.role_id SET e.role_key = CASE WHEN r.role_key='owner' THEN 'owner' WHEN r.role_key='admin_dapur' THEN 'admin_dapur' WHEN r.role_key='manager_dapur' THEN 'manager_dapur' ELSE 'pegawai_dapur' END;
