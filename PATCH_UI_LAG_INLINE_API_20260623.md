# Patch UI Lag Dapur Adena - 20260623e

## Fokus patch
- Tidak mengubah alur API transfer toko/Adena.
- Memperbaiki lag/perceived freeze di UI Dapur.
- Membuat tombol Ping, Test Produk, dan Test Transfer di menu Toko & API berjalan inline via AJAX agar halaman tidak blank/full reload.
- Menscope table scroll agar rule `display:block; width:max-content` tidak kena semua tabel global.
- Merapikan form Toko & API agar lebih desktop friendly.

## File yang berubah
- `admin/index.php`
- `assets/app.js`
- `assets/app.css`

## Cara pasang
Upload/replace file sesuai struktur folder pada aplikasi Dapur.
Setelah upload, lakukan hard refresh browser: Ctrl+F5.

## Backtest lokal
- PHP lint: OK.
- JavaScript syntax check: OK.
- Tidak ada perubahan database.
- Tidak ada file toko/Adena yang perlu diubah.
