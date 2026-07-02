Patch 20260702 - Dapur

Perubahan minimal sesuai permintaan:
1. Notifikasi pairing API di topbar kanan atas admin Dapur.
2. Menu API disatukan sebagai Admin -> API & Integrasi.
3. Request pairing masuk/keluar, approval, koneksi aktif, link koneksi toko dan token manual dirapikan di satu halaman.
4. Sidebar submenu dibuat accordion/dropdown untuk Produk Jadi, BOM, Kegiatan Pegawai, dan Admin.
5. Hide Produk berada di bawah Produk Jadi; Hide BOM berada di bawah BOM; Daftar Kegiatan Pegawai berada di bawah Kegiatan Pegawai.
6. KPI/Kegiatan Pegawai ditambah total poin bulanan per pegawai dan filter bulan.
7. Pegawai dapat dihapus dari daftar aktif; riwayat aktivitas tetap disimpan.
8. Endpoint back office employees diperbaiki agar sesuai kolom employee_name.
9. Endpoint baru api/backoffice/kpi_dapur.php.
10. Dashboard summary Dapur menambahkan omset Dapur bulan ini dari harga jual Dapur aktual: kitchen_sales_headers.total_amount.
11. Dashboard summary juga mengirim breakdown omset Dapur per toko tujuan.

Update DB opsional/safe:
updates/20260702_api_kpi_dashboard_patch.sql
