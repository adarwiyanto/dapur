Dapur Adena v1 - standalone

Cara install di hosting:
1. Upload seluruh isi folder dapur-adena-v1 ke domain/subdomain baru, misalnya dapur.adena.co.id.
2. Buat database MySQL kosong.
3. Buka https://domain-dapur/install/index.php.
4. Isi host database, nama database, user, password, base URL, dan admin awal.
5. Setelah sukses login, hapus/rename folder install.
6. Masuk menu Toko & API, buat daftar toko tujuan, isi Base URL website toko dan token API dari patch toko.
7. Import produk toko dari menu Produk Jadi, lalu buat BOM backward.
8. Produksi akan mengurangi bahan baku dan menambah produk jadi.
9. Penjualan ke Toko akan menjadi penjualan bagi dapur dan dikirim ke API toko sebagai penerimaan stok.

Catatan keamanan:
- Gunakan HTTPS.
- Token API toko dibuat per toko.
- Backup database sebelum update berikutnya.
