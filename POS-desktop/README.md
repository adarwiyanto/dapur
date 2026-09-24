# Dapur Adena POS Desktop

Aplikasi POS Windows offline-first untuk Dapur Adena.

## Karakteristik
- Backend fixed: `https://dapur.adena.co.id` (tidak dapat diubah user).
- SQLite lokal; transaksi tetap dapat dibuat ketika offline.
- Sinkronisasi otomatis setiap 5 menit dan manual dari menu Sinkronisasi.
- Tanpa open/close shift.
- Login online pertama kali, sesi disimpan terenkripsi melalui Electron `safeStorage`.
- Tidak pernah membuka koneksi MySQL langsung dari desktop.
- Transaksi menggunakan UUID agar retry sinkronisasi tidak membuat transaksi ganda.

## Pengembangan
```bash
npm install
npm start
```

## Build Windows
Jalankan pada Windows (direkomendasikan):
```bash
npm install
npm run dist
```
Hasil installer/portable ada di folder `dist/`.

## Backend
Install migration `updates/20260924_pos_desktop.sql` pada server, lalu deploy folder `api/pos/`.
