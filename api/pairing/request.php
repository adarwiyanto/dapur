<?php
require_once __DIR__ . '/../../core/api_pairing.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') pairing_err('Method tidak diizinkan',405);
ensure_api_pairing_schema(); $in=pairing_input();
$requesterName=trim((string)($in['requester_name']??'Aplikasi Peminta'));
$requesterType=trim((string)($in['requester_type']??'external'));
$requesterUrl=pairing_normalize_url((string)($in['requester_base_url']??''));
$targetType=trim((string)($in['target_type']??'adena_store'));
$code=trim((string)($in['request_code']??pairing_request_code('PAIR')));
$secretHash=trim((string)($in['request_secret_hash']??''));
$callback=pairing_normalize_url((string)($in['callback_url']??''));
$requested=trim((string)($in['requested_scope']??''));
$providedToken=trim((string)($in['access_token']??''));
$providedTokenHash=strtolower(trim((string)($in['access_token_hash']??'')));
if($requesterUrl==='') pairing_err('Requester base URL wajib diisi.',422);
// Back Office adalah penerbit token. Dapur hanya menerima dan menyimpan token yang
// dikirim Back Office agar kontrak pairing sama dengan koneksi Back Office–Toko.
if(strtolower($requesterType)==='backoffice'){
  if(strlen($providedToken)<20) pairing_err('Token koneksi dari Back Office kosong/tidak valid.',422);
  $calculatedHash=hash('sha256',$providedToken);
  if($providedTokenHash!=='' && !hash_equals($calculatedHash,$providedTokenHash)) pairing_err('Hash token koneksi tidak sesuai.',422);
  $providedTokenHash=$calculatedHash;
}
$scope=($requested!=='' && pairing_allowed_scope($requested)) ? $requested : pairing_scope_for($requesterType,$targetType);
$exists=db()->prepare('SELECT id,status FROM api_pairing_requests WHERE request_code=? LIMIT 1'); $exists->execute([$code]); $old=$exists->fetch(PDO::FETCH_ASSOC);
if($old) pairing_ok(['message'=>'Request pairing sudah tercatat.','request_code'=>$code,'status'=>$old['status']]);
$st=db()->prepare("INSERT INTO api_pairing_requests(direction,request_code,request_secret_hash,requester_name,requester_type,requester_base_url,target_type,requested_scope,status,callback_url,access_token_plain,token_hash,expires_at,created_at,last_message) VALUES('incoming',?,?,?,?,?,?,?,'pending',?,?,?,DATE_ADD(NOW(), INTERVAL 48 HOUR),NOW(),'Menunggu approval')");
$st->execute([$code,$secretHash,$requesterName,$requesterType,$requesterUrl,$targetType,$scope,$callback,$providedToken!==''?$providedToken:null,$providedTokenHash!==''?$providedTokenHash:null]);
pairing_log_event(null,'api/pairing/request.php','in','pair_request_in','Permintaan pairing diterima.',['request_code'=>$code,'requester'=>$requesterUrl,'scope'=>$scope]);
pairing_ok(['message'=>'Permintaan pairing diterima. Menunggu approval owner/admin.','request_code'=>$code,'status'=>'pending','requested_scope'=>$scope]);
