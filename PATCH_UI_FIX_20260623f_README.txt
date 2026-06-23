Patch Dapur Adena 20260623f - UI Toko & API

Fokus patch:
- Memperbaiki respon AJAX Test API agar JSON bersih, tidak tercampur HTML admin shell.
- Menambahkan cache-buster CSS/JS agar browser tidak memakai app.js/app.css lama.
- Memperbaiki layout Toko & API: sidebar desktop tetap muncul, checkbox Aktif normal, form tidak memanjang liar.
- Membatasi efek rule tabel lama agar tidak memaksa semua tabel menjadi display:block.
- Tidak mengubah alur API transfer toko/Adena.
- Tidak ada perubahan database.

File yang diganti:
- admin/index.php
- assets/app.js
- assets/app.css

Setelah upload:
1. Replace file sesuai path.
2. Hard refresh browser Ctrl+F5.
3. Test halaman Toko & API: Ping, Test Produk, Test Transfer.
