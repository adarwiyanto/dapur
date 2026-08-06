PATCH 20260806a - CUSTOMER DAN DISKON PENJUALAN

Perubahan:
1. Menu Setting > Daftar Customer untuk customer non-toko.
2. Form Penjualan / Distribusi dapat memilih toko terintegrasi atau customer lainnya.
3. Customer non-toko tidak memanggil API toko.
4. Diskon per item: tanpa diskon, persen, atau nominal.
5. Semua kalkulasi backend terpusat di core/sales_calculator.php.
6. Nilai netto final tetap disimpan dan dibaca dari kitchen_sales_headers.total_amount.
7. Supplier dan modul lain tidak diubah.

Instalasi database:
- Sistem menjalankan penambahan tabel/kolom secara otomatis saat halaman admin dibuka.
- Untuk instalasi manual, jalankan updates/20260806_customer_discount_sales.sql satu kali.
- Bila kolom sudah ada, abaikan error duplicate column pada instalasi manual.

Catatan kompatibilitas:
- store_id tetap dipakai untuk tujuan toko terintegrasi.
- customer_id hanya dipakai untuk customer non-toko.
- Data transaksi lama tetap terbaca; original_price lama akan fallback ke transfer_price pada faktur.
