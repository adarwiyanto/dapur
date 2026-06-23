# Patch UI Lag Dapur Adena - 2026-06-23

## Fokus patch
1. Menghapus overlay global submit yang membuat UI terasa freeze.
2. Mengganti perhitungan stok per-item/N+1 query menjadi bulk stock map.
3. Mengubah halaman Produk Jadi menjadi search/filter/pagination server-side.
4. Mengubah flow transfer stok agar posting lokal cepat; pengiriman API ke toko dilakukan dari tombol Kirim API di riwayat.
5. Mempertahankan payload API transfer dan fitur lain yang tidak diminta.

## File berubah
- `assets/app.js`
- `assets/app.css`
- `core/helpers.php`
- `admin/index.php`

## Backtest 5 agent

### Agent 1 - Produk Jadi banyak item
- Sebelum: semua produk dirender sekaligus, stok dihitung per produk.
- Sesudah: list dibatasi 25/50/100 per halaman, query stok memakai subquery agregat.
- Ekspektasi: buka halaman lebih ringan dan search tidak membebani DOM besar.

### Agent 2 - Filter/search Produk Jadi
- Sebelum: filter client-side terhadap seluruh row.
- Sesudah: filter via GET/server-side (`q`, `source`, `stock`, `per_page`).
- Ekspektasi: mengetik/scroll tidak lag karena browser tidak memproses ribuan row.

### Agent 3 - Stok dan stock opname
- Sebelum: `stock_qty()` dipanggil per item.
- Sesudah: `stock_qty_map()` mengambil stok banyak item dalam satu query.
- Ekspektasi: halaman stok/opname lebih cepat saat item dan ledger membesar.

### Agent 4 - Posting transfer stok
- Sebelum: setelah transaksi lokal, server langsung call API toko; UI menunggu timeout koneksi/API.
- Sesudah: posting lokal selesai dulu dengan status `posted`; user klik `Kirim API` dari riwayat untuk sync ke toko.
- Ekspektasi: tombol posting tidak terasa freeze walau API toko lambat.

### Agent 5 - Submit form umum
- Sebelum: overlay global menutup layar dan terasa seperti aplikasi hang.
- Sesudah: hanya tombol yang dikunci dan diberi state `is-loading`, tanpa overlay global.
- Ekspektasi: UI tetap responsif secara visual.

## Catatan penting
- Patch ini tidak mengubah skema database.
- Status transfer lokal memakai status existing `posted`, sehingga aman untuk DB lama.
- Endpoint dan payload transfer ke toko tetap memakai `api/v1/kitchen/receive-transfer.php` dan `build_transfer_payload()` yang sudah ada.
