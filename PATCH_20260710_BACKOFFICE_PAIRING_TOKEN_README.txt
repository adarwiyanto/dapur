PATCH 2026-07-10 - Penyamaan Pairing Back Office ke Dapur

Tujuan
- Back Office tetap tidak diubah.
- Dapur mengikuti pola pairing yang sudah dipakai Back Office ke toko.
- Token koneksi diterbitkan oleh Back Office, diterima oleh Dapur, lalu dipakai sebagai token final setelah approval.
- UI tidak diubah.

File yang berubah
1. api/pairing/request.php
   - Menerima access_token dan access_token_hash dari Back Office.
   - Memvalidasi hash token.
   - Menyimpan token tersebut pada request pairing incoming.
   - Untuk requester_type=backoffice, token dari Back Office wajib tersedia.

2. admin/api_pairing_action.php
   - Approval pairing Back Office tidak lagi membuat token baru di Dapur.
   - Menggunakan token yang sudah diterbitkan Back Office.
   - Validasi ulang hash sebelum aktivasi.
   - Transaksi database untuk mencegah kondisi setengah tersimpan.
   - Koneksi aktif lama dengan request_code yang sama direvoke sebelum koneksi baru dibuat.
   - Pairing non-Back Office tetap memakai fallback lama agar fitur lain tidak terganggu.

Langkah setelah upload
1. Upload/replace dua file di atas.
2. Hapus koneksi Dapur lama di Back Office dan request pairing lama di Dapur bila masih ada.
3. Buat pairing baru dari Back Office ke Dapur.
4. Approve di Dapur.
5. Jalankan Test Koneksi dari Back Office.

Tidak ada perubahan database dan tidak ada perubahan UI.
