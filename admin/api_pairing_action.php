<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/api_pairing.php';
require_login(); ensure_api_pairing_schema(); if(function_exists('verify_csrf')) verify_csrf();
$me=current_user(); $uid=(int)($me['id']??0); $act=(string)($_POST['act']??'');
function go_pair($m=''){
  $page=preg_replace('/[^a-z0-9_]/i','', (string)($_POST['return_page']??'api_integrations')); if($page==='') $page='api_integrations';
  header('Location: index.php?page='.$page.($m?'&msg='.urlencode($m):'')); exit;
}
function dapur_remote_token(array $c): string { return (string)($c['token_plain'] ?? ($c['access_token_plain'] ?? '')); }
function dapur_store_call(array $store,string $path,array $payload=[],string $method='POST'): array {
  $url=pairing_normalize_url((string)$store['api_base_url']).'/'.ltrim($path,'/');
  $headers=['Accept: application/json','Content-Type: application/json']; if(!empty($store['api_token'])) $headers[]='Authorization: Bearer '.$store['api_token'];
  $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3]);
  if(strtoupper($method)==='GET'){} else { curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method); curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
  $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); $json=is_string($body)?json_decode($body,true):null;
  return ['http_code'=>$code,'body'=>(string)$body,'curl_error'=>(string)$err,'json'=>is_array($json)?$json:null,'ok'=>($err==='' && $code>=200 && $code<300 && is_array($json) && !empty($json['ok']))];
}
function dapur_test_item(int $finishedProductId = 0): array {
  if($finishedProductId>0){
    try{ $r=one('SELECT id,code,sku,name,category,unit,transfer_price,source_product_id FROM finished_products WHERE id=? AND is_active=1 LIMIT 1',[$finishedProductId]); if($r) return ['store_product_id'=>(string)($r['source_product_id']?:$r['id']),'sku'=>(string)($r['sku']?:($r['code']?:'DAPUR-FP-'.(int)$r['id'])),'name'=>(string)$r['name'],'category'=>(string)($r['category']?:'Kiriman Dapur'),'item_type'=>'finished_good','qty'=>1,'unit'=>(string)($r['unit']?:'pcs'),'transfer_price'=>(float)($r['transfer_price']??0)]; }catch(Throwable $e){}
  }
  try{ $r=one('SELECT id,name,unit,last_cost FROM raw_materials WHERE is_active=1 ORDER BY id LIMIT 1'); if($r) return ['sku'=>'DAPUR-RM-'.(int)$r['id'],'name'=>(string)$r['name'],'item_type'=>'raw_material','qty'=>1,'unit'=>(string)($r['unit']?:'pcs'),'transfer_price'=>(float)($r['last_cost']??0)]; }catch(Throwable $e){}
  try{ $r=one('SELECT id,name,category,unit,transfer_price FROM finished_products WHERE is_active=1 ORDER BY id LIMIT 1'); if($r) return ['store_product_id'=>(string)$r['id'],'sku'=>'DAPUR-FP-'.(int)$r['id'],'name'=>(string)$r['name'],'category'=>(string)($r['category']?:'Kiriman Dapur'),'item_type'=>'finished_good','qty'=>1,'unit'=>(string)($r['unit']?:'pcs'),'transfer_price'=>(float)($r['transfer_price']??0)]; }catch(Throwable $e){}
  return ['sku'=>'DAPUR-DRYRUN','name'=>'Tes Barang Dry Run','item_type'=>'raw_material','qty'=>1,'unit'=>'pcs','transfer_price'=>0];
}

function dapur_scope_can_import(array $c): bool { return pairing_scope_allows((string)($c['access_scope'] ?? ''),'products.read'); }
function dapur_scope_can_transfer(array $c): bool { return pairing_scope_allows((string)($c['access_scope'] ?? ''),'stock_transfer.write'); }
function dapur_revoke_remote_connection(array $c): array {
  $token=dapur_remote_token($c);
  if($token==='' || empty($c['remote_base_url'])) return ['ok'=>false,'message'=>'Token/base URL remote kosong.'];
  return pairing_remote_json((string)$c['remote_base_url'],'api/pairing/revoke.php',['reason'=>'revoked_from_dapur'],'POST',$token,10);
}
try{
 if($act==='create_request'){
   $name=trim((string)($_POST['connection_name']??'Koneksi Baru')); $url=pairing_normalize_url((string)($_POST['base_url']??'')); $target=trim((string)($_POST['target_type']??'adena_store'));
   if($url==='') throw new RuntimeException('URL tujuan wajib diisi.');
   $secret=pairing_secret(); $code=pairing_request_code($target==='hope'?'DAPUR2HOPE':'ADENA'); $scope=pairing_scope_for('dapur',$target); $hash=password_hash($secret,PASSWORD_DEFAULT);
   $payload=['request_code'=>$code,'request_secret_hash'=>$hash,'requester_name'=>(app_config()['app']['name'] ?? 'Dapur Adena'),'requester_type'=>'dapur','requester_base_url'=>app_config()['app']['base_url']??'','target_type'=>$target,'requested_scope'=>$scope,'callback_url'=>''];
   $res=pairing_remote_json($url,'api/pairing/request.php',$payload,'POST'); $ok=!empty($res['ok']); $message=(string)($res['message']??$res['_error']??'');
   db()->prepare("INSERT INTO api_pairing_requests(direction,request_code,request_secret_hash,requester_name,requester_type,requester_base_url,target_name,target_type,target_base_url,requested_scope,status,access_token_plain,last_message,created_at) VALUES('outgoing',?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
     ->execute([$code,$hash,$payload['requester_name'],'dapur',(string)$payload['requester_base_url'],$name,$target,$url,$scope,$ok?'pending':'failed',$secret,$message]);
   pairing_log_event(null,'api/pairing/request.php','out',$ok?'pair_request_sent':'pair_request_failed',$message,['target'=>$url,'request_code'=>$code,'response'=>$res]);
   go_pair($ok?'Request pairing terkirim. Approve di tujuan, lalu klik Cek Status.':'Request gagal: '.$message);
 }
 if($act==='approve'){
   $id=(int)($_POST['id']??0); $r=db()->prepare("SELECT * FROM api_pairing_requests WHERE id=? AND direction='incoming' AND status='pending'"); $r->execute([$id]); $req=$r->fetch(PDO::FETCH_ASSOC); if(!$req) throw new RuntimeException('Request tidak ditemukan.');
   $token='dapur_'.bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
   db()->prepare("INSERT INTO api_connections(connection_name,connection_type,remote_system_type,remote_base_url,access_scope,token_hash,token_plain,access_token_plain,status,paired_from_request_code,paired_by,paired_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())")
     ->execute([$req['requester_name'],$req['requester_type'],$req['requester_type'],$req['requester_base_url'],$req['requested_scope'],$hash,$token,$token,'active',$req['request_code'],$uid]);
   db()->prepare("UPDATE api_pairing_requests SET status='approved',access_token_plain=?,token_hash=?,approved_by=?,approved_at=NOW(),last_message='Approved',updated_at=NOW() WHERE id=?")->execute([$token,$hash,$uid,$id]);
   pairing_log_event(null,'api/pairing/approve','in','pair_request_approved','Pairing disetujui.',['request_code'=>$req['request_code'],'requester'=>$req['requester_base_url']]);
   go_pair('Pairing disetujui. Token otomatis siap dipakai oleh peminta.');
 }
 if($act==='reject'){
   $id=(int)($_POST['id']??0); db()->prepare("UPDATE api_pairing_requests SET status='rejected',reject_reason=?,rejected_by=?,rejected_at=NOW(),last_message='Rejected',updated_at=NOW() WHERE id=? AND direction='incoming'")->execute([trim((string)($_POST['reason']??'')),$uid,$id]); go_pair('Pairing ditolak.');
 }
 if($act==='check_status'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_pairing_requests WHERE id=? AND direction='outgoing'"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException('Request outgoing tidak ditemukan.');
   $secret=(string)($r['access_token_plain']??''); if($secret==='') throw new RuntimeException('Secret lokal kosong. Kirim ulang pairing.');
   $res=pairing_remote_json($r['target_base_url'],'api/pairing/status.php',['request_code'=>$r['request_code'],'request_secret'=>$secret],'GET'); $status=(string)($res['status']??($res['ok']?'pending':'failed')); $message=(string)($res['message']??$res['_error']??'');
   db()->prepare('UPDATE api_pairing_requests SET status=?,last_checked_at=NOW(),last_message=?,updated_at=NOW() WHERE id=?')->execute([$status,$message,$id]);
   if($status==='approved' && !empty($res['access_token'])){
     $token=(string)$res['access_token']; $hash=hash('sha256',$token); $scope=(string)($res['access_scope']??$r['requested_scope']);
     db()->prepare("INSERT INTO api_connections(connection_name,connection_type,remote_system_type,remote_base_url,access_scope,token_hash,token_plain,access_token_plain,status,paired_from_request_code,paired_by,paired_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())")
       ->execute([$r['target_name']?:$r['target_base_url'],$r['target_type'],$r['target_type'],$r['target_base_url'],$scope,$hash,$token,$token,'active',$r['request_code'],$uid]);
     pairing_log_event(null,'api/pairing/status.php','out','pair_status_approved','Koneksi aktif.',['request_code'=>$r['request_code'],'target'=>$r['target_base_url']]);
   } else pairing_log_event(null,'api/pairing/status.php','out',$status==='failed'?'pair_status_failed':'pair_status_checked',$message,['request_code'=>$r['request_code'],'response'=>$res]);
   go_pair('Status pairing: '.$status.($message!==''?' - '.$message:''));
 }
 if($act==='test_connection'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_connections WHERE id=? AND status='active'"); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi tidak ditemukan.');
   $token=dapur_remote_token($c); if($token==='') throw new RuntimeException('Koneksi ini tidak punya token keluar untuk test remote.');
   $res=pairing_remote_json($c['remote_base_url'],'api/pairing/test.php',[],'GET',$token); $ok=!empty($res['ok']); $msg=(string)($res['message']??$res['_error']??'');
   db()->prepare('UPDATE api_connections SET last_test_at=NOW(),last_test_status=?,last_test_message=? WHERE id=?')->execute([$ok?'ok':'failed',$msg,$id]);
   pairing_test_log($id,(string)$c['remote_base_url'],(string)$c['remote_system_type'],'api/pairing/test.php',$ok?'ok':'failed',(int)($res['_http_code']??0),$msg,$res,$uid);
   pairing_log_event(null,'api/pairing/test.php','out',$ok?'test_connection_ok':'test_connection_failed',$msg,['connection_id'=>$id,'response'=>$res]);
   go_pair($ok?'Test koneksi berhasil.':'Test koneksi gagal: '.$msg);
 }

 if($act==='test_hope_products'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_connections WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi HOPe tidak ditemukan.');
   if(!dapur_scope_can_import($c)) throw new RuntimeException('Scope koneksi belum bisa ambil produk. Klik Refresh Scope atau pairing ulang. Scope saat ini: '.($c['access_scope']??''));
   $token=dapur_remote_token($c); if($token==='') throw new RuntimeException('Token HOPe kosong. Cek status pairing dulu.');
   $res=pairing_remote_json($c['remote_base_url'],'api/v1/kitchen/products.php',[],'GET',$token); $items=is_array($res['items']??null)?$res['items']:[];
   $ok=!empty($res['ok']) && is_array($items); $msg=$ok?('Test ambil produk HOPe/HP berhasil. Produk terbaca: '.count($items).' item.'):(string)($res['message']??$res['_error']??'Test produk gagal.');
   db()->prepare('UPDATE api_connections SET last_test_at=NOW(),last_test_status=?,last_test_message=? WHERE id=?')->execute([$ok?'ok':'failed',$msg,$id]);
   pairing_test_log($id,(string)$c['remote_base_url'],'hope','api/v1/kitchen/products.php',$ok?'ok':'failed',(int)($res['_http_code']??0),$msg,$res,$uid);
   pairing_log_event(null,'api/v1/kitchen/products.php','out',$ok?'hope_products_test_ok':'hope_products_test_failed',$msg,['connection_id'=>$id,'response'=>$res]);
   go_pair($msg);
 }
 if($act==='refresh_scope'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_connections WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi tidak ditemukan.');
   $token=dapur_remote_token($c); if($token==='') throw new RuntimeException('Token remote kosong.');
   $desired=(($c['remote_system_type']??'')==='hope' || ($c['connection_type']??'')==='hope')?'dapur_stock_sender':'products.read';
   $res=pairing_remote_json((string)$c['remote_base_url'],'api/pairing/refresh-scope.php',['desired_scope'=>$desired],'POST',$token,12);
   $ok=!empty($res['ok']); $newScope=(string)($res['access_scope']??$desired); $msg=(string)($res['message']??$res['_error']??'');
   if($ok){ db()->prepare('UPDATE api_connections SET access_scope=?, last_test_at=NOW(), last_test_status=?, last_test_message=?, updated_at=NOW() WHERE id=?')->execute([$newScope,'ok','Scope diperbarui: '.$newScope,$id]); }
   else { db()->prepare('UPDATE api_connections SET last_test_at=NOW(), last_test_status=?, last_test_message=? WHERE id=?')->execute(['failed','Refresh scope gagal: '.$msg,$id]); }
   pairing_test_log($id,(string)$c['remote_base_url'],(string)$c['remote_system_type'],'api/pairing/refresh-scope.php',$ok?'ok':'failed',(int)($res['_http_code']??0),$msg,$res,$uid);
   pairing_log_event(null,'api/pairing/refresh-scope.php','out',$ok?'scope_refresh_ok':'scope_refresh_failed',$msg,['connection_id'=>$id,'response'=>$res]);
   go_pair($ok?'Scope koneksi diperbarui menjadi '.$newScope.'.':'Refresh scope gagal: '.$msg);
 }
 if($act==='test_hope_transfer'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_connections WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi HOPe tidak ditemukan.');
   if(!dapur_scope_can_transfer($c)) throw new RuntimeException('Koneksi aktif tetapi scope belum boleh transfer stok. Scope saat ini: '.($c['access_scope']??'').'. Klik Refresh Scope di menu API & Integrasi.');
   $token=dapur_remote_token($c); if($token==='') throw new RuntimeException('Token HOPe kosong. Cek status pairing dulu.');
   $productId=(int)($_POST['product_id']??0);
   $testItem=dapur_test_item($productId);
   $payload=['dry_run'=>true,'source'=>'DAPUR_ADENA','transfer_no'=>'DRYRUN-DAPUR-'.date('YmdHis').'-'.$id.($productId>0?'-FP'.$productId:''),'transfer_date'=>date('Y-m-d'),'items'=>[$testItem],'notes'=>'Dry-run test transfer stok Dapur ke HOPe/HP. Tidak mengubah stok.'];
   $res=pairing_remote_json($c['remote_base_url'],'api/v1/kitchen/receive-transfer.php',$payload,'POST',$token); $ok=!empty($res['ok']); $msg=(string)($res['message']??$res['_error']??'');
   db()->prepare('UPDATE api_connections SET last_test_at=NOW(),last_test_status=?,last_test_message=? WHERE id=?')->execute([$ok?'ok':'failed',$msg,$id]);
   pairing_test_log($id,(string)$c['remote_base_url'],'hope','api/v1/kitchen/receive-transfer.php',$ok?'ok':'failed',(int)($res['_http_code']??0),$msg,$res,$uid);
   pairing_log_event(null,'api/v1/kitchen/receive-transfer.php','out',$ok?'dryrun_transfer_ok':'dryrun_transfer_failed',$msg,['request'=>$payload,'response'=>$res]);
   $testedName=(string)($testItem['name']??'item');
   go_pair($ok?'Test transfer stok ke HOPe/HP berhasil untuk '.$testedName.'. Dry-run tidak mengubah stok.':'Test transfer stok ke HOPe/HP gagal untuk '.$testedName.': '.$msg);
 }
 if($act==='store_test_ping' || $act==='store_test_products' || $act==='store_test_transfer'){
   $id=(int)($_POST['id']??0); $s=one('SELECT * FROM stores WHERE id=?',[$id]); if(!$s) throw new RuntimeException('Toko/API tidak ditemukan.');
   $endpoint=$act==='store_test_ping'?'api/v1/kitchen/ping.php':($act==='store_test_products'?'api/v1/kitchen/products.php':'api/v1/kitchen/receive-transfer.php');
   $payload=[]; $method='GET'; if($act==='store_test_transfer'){ $method='POST'; $payload=['dry_run'=>true,'store_code'=>$s['store_code'],'source'=>'DAPUR_ADENA','transfer_no'=>'TEST-'.date('YmdHis').'-'.$id,'transfer_date'=>date('Y-m-d'),'items'=>[['store_product_id'=>'DRYRUN','sku'=>'DRYRUN','name'=>'Tes Transfer Dry Run','qty'=>1,'unit'=>'pcs','transfer_price'=>0]],'notes'=>'Dry-run test transfer dari menu API.']; }
   $res=dapur_store_call($s,$endpoint,$payload,$method); $msg=$res['ok']?('Test '.$endpoint.' sukses.'):('Test '.$endpoint.' gagal. HTTP '.$res['http_code'].' '.($res['curl_error']?:($res['json']['message']??$res['json']['error']??'')));
   pairing_log_event($id,$endpoint,'out',$res['ok']?'store_test_ok':'store_test_failed',$msg,['request'=>$payload,'response'=>['http_code'=>$res['http_code'],'curl_error'=>$res['curl_error'],'body'=>pairing_short($res['body'])]]);
   go_pair($msg);
 }
 if($act==='delete_request'){
   $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Request tidak valid.');
   $st=db()->prepare('SELECT * FROM api_pairing_requests WHERE id=? LIMIT 1'); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException('Request tidak ditemukan.');
   if(($r['status']??'')==='approved') db()->prepare("UPDATE api_pairing_requests SET status='cancelled',last_message='Dibatalkan dari menu Dapur',updated_at=NOW() WHERE id=?")->execute([$id]); else db()->prepare('DELETE FROM api_pairing_requests WHERE id=?')->execute([$id]);
   go_pair('Request pairing dihapus/dibatalkan.');
 }
 if($act==='revoke_connection'){
   $id=(int)($_POST['id']??0); $st=db()->prepare('SELECT * FROM api_connections WHERE id=? LIMIT 1'); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi tidak ditemukan.');
   $remote=dapur_revoke_remote_connection($c);
   db()->prepare("UPDATE api_connections SET status='revoked',revoked_by=?,revoked_at=NOW(),last_test_status='revoked',last_test_message='Dicabut dari menu Dapur',updated_at=NOW() WHERE id=?")->execute([$uid,$id]);
   if(!empty($c['paired_from_request_code'])) db()->prepare("UPDATE api_pairing_requests SET status='cancelled',last_message='Koneksi dicabut dari menu Dapur',updated_at=NOW() WHERE request_code=?")->execute([(string)$c['paired_from_request_code']]);
   pairing_log_event(null,'api_connections','out','connection_revoked','Koneksi dicabut. Remote revoke: '.(!empty($remote['ok'])?'ok':($remote['message']??'gagal')),['connection_id'=>$id,'remote_response'=>$remote]);
   go_pair('Koneksi dihapus dari tampilan. Revoke remote: '.(!empty($remote['ok'])?'berhasil':'belum/sedang dicoba'));
 }

}catch(Throwable $e){ pairing_log_event(null,'admin/api_pairing_action.php','out','action_error',$e->getMessage(),['act'=>$act]); go_pair('Error: '.$e->getMessage()); }
go_pair();
