-- Patch: role owner, pengaturan permission, dan hide produk jadi
-- Aman untuk DB lama: tidak drop tabel dan tidak menghapus histori transaksi.

UPDATE roles SET role_key='owner', role_name='Owner' WHERE id=1 OR role_key='superadmin';
INSERT IGNORE INTO roles(id,role_key,role_name) VALUES (1,'owner','Owner');

INSERT IGNORE INTO permissions(permission_key,permission_name) VALUES
('permissions','Pengaturan Permission');

-- Pastikan admin tetap punya akses lama dan permission baru hanya bila memang diperlukan oleh owner via UI.
-- Owner tidak perlu role_permissions karena selalu full access dari aplikasi.
