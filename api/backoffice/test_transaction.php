<?php
require_once __DIR__ . '/../../core/backoffice_resources.php';
$conn=pairing_auth('transactions.read');
$required=['purchase_headers','production_headers','kitchen_sales_headers','stock_ledger']; $missing=[]; foreach($required as $t){ if(!pairing_table_exists($t)) $missing[]=$t; }
if($missing) pairing_err('Tabel transaksi belum lengkap.',500,['missing_tables'=>$missing]);
if((string)($_GET['dry_run']??'')==='1') pairing_ok(['message'=>'Dry-run transaksi berhasil. Tidak ada transaksi dibuat dan stok tidak berubah.','checks'=>['database'=>true,'auth'=>true,'tables'=>true]]);
pairing_ok(['message'=>'Endpoint transaksi aktif. Gunakan resources.php untuk membaca dan actions.php untuk operasi yang diizinkan.']);
