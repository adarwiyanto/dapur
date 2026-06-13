PATCH: Import produk dari toko + progress bar

File yang berubah/ditambah:
1. admin/index.php
   - Form import produk diberi atribut AJAX dan area progress bar.
   - Fallback POST lama tetap ada jika JavaScript mati.

2. admin/import_products_ajax.php
   - Endpoint AJAX khusus import produk.
   - Validasi toko aktif, cURL, HTTP code, JSON response, dan struktur products/data.
   - Import tidak lagi bergantung pada unique key finished_products.
   - Produk lama dari source_store_id + source_product_id di-update, produk baru di-insert.
   - Mapping finished_product_store_mappings tetap di-upsert.
   - Log product_import_logs dan api_logs ditulis.

3. assets/app.js
   - Menangkap submit form import produk saja.
   - Menampilkan progress 8% sampai 100% dan pesan gagal/berhasil.
   - Fitur data-confirm lama tetap dipertahankan.

4. assets/app.css
   - CSS progress bar dengan selector .import-progress saja.

5. updates/20260613_import_produk_progress.sql
   - Index non-unique opsional untuk mempercepat lookup produk sumber.
   - Aman dijalankan berulang.

Cara pasang:
1. Backup file dan database.
2. Upload/replace file patch ke folder dapur.
3. Jalankan SQL updates/20260613_import_produk_progress.sql di database dapur.
4. Buka admin > Produk Jadi > Import Produk dari Toko.
5. Klik Import Semua Elemen Produk. Progress bar dan status akan tampil.

Catatan:
- Patch ini tidak mengubah BOM, produksi, stok, penjualan, user, role, atau API token.
- Jika import gagal, pesan error akan menjelaskan HTTP/cURL/JSON/struktur produk.
