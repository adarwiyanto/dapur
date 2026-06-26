<?php
require_once __DIR__.'/../core/auth.php'; require_login(); verify_csrf();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
ob_start(); // patch: keep AJAX JSON clean even when admin shell is buffered
$u=current_user(); $page=$_GET['page']??'dashboard';
$menus=[
 'dashboard'=>['Dashboard','🏠','dashboard'], 'stores'=>['Toko & API','🔌','stores'], 'finished'=>['Produk Jadi','📦','products'], 'finished_hidden'=>['Hide Produk','↳','products'], 'raw'=>['Bahan Baku','🥣','raw_materials'], 'purchases'=>['Pembelian','🛒','purchases'], 'bom'=>['BOM','🧾','bom'], 'bom_hidden'=>['Hide BOM','↳','bom'], 'production'=>['Produksi','🏭','production'], 'stock'=>['Stok','📊','stock'], 'stock_opname'=>['Stok Opname','🧮','stock_opname'], 'sales'=>['Penjualan ke Toko','🚚','sales_distribution'], 'activities'=>['Kegiatan Pegawai','⭐','activities'], 'activity_types'=>['Daftar Kegiatan Pegawai','↳','activities'], 'remuneration'=>['Remunerasi','💰','remuneration'], 'users'=>['User & Role','👤','users'], 'error_log'=>['Error Log','🧯','error_log','owner'], 'owner_permissions'=>['Pengaturan Permission','🛡️','permissions','owner'], 'api'=>['API Token','🔐','api']
];
if(!isset($menus[$page])) $page='dashboard'; require_perm($menus[$page][2]); if(($menus[$page][3]??'')==='owner' && !is_owner()){ http_response_code(403); die('Akses ditolak.'); }
function h2($t){echo '<h2>'.e($t).'</h2>';}
function next_no($prefix,$table,$field){return $prefix.'-'.date('Ymd').'-'.str_pad((string)(((int)(db()->query("SELECT COUNT(*) FROM $table")->fetchColumn()))+1),4,'0',STR_PAD_LEFT);} 
function postval($k,$d=''){return trim((string)($_POST[$k]??$d));}
function role_key(): string { $u=current_user(); return (string)($u['role_key']??''); }
function can_manage_finished_delete(): bool { return is_owner() || role_key()==='admin_dapur'; }
function finished_product_ref_count(int $id): int {
 $refs=0;
 $checks=[
  ['kitchen_sales_items','finished_product_id'],
  ['stock_ledger',null],
  ['production_headers','finished_product_id'],
  ['bom_headers','finished_product_id']
 ];
 foreach($checks as $c){
  if(!table_exists($c[0])) continue;
  if($c[0]==='stock_ledger') $refs+=(int)one("SELECT COUNT(*) c FROM stock_ledger WHERE item_type='finished' AND item_id=?",[$id])['c'];
  else $refs+=(int)one('SELECT COUNT(*) c FROM '.$c[0].' WHERE '.$c[1].'=?',[$id])['c'];
 }
 return $refs;
}

function bom_ref_count(int $id): int {
 $refs=0;
 if(table_exists('production_headers')) $refs+=(int)one('SELECT COUNT(*) c FROM production_headers WHERE bom_id=?',[$id])['c'];
 return $refs;
}
function save_bom_items(int $bomId, array $rawIds, array $qtys): int {
 execq('DELETE FROM bom_items WHERE bom_id=?',[$bomId]);
 $saved=0;
 foreach($rawIds as $i=>$rid){
  $rid=(int)$rid; $qty=(float)($qtys[$i]??0);
  if($rid<=0||$qty<=0) continue;
  $rm=one('SELECT unit FROM raw_materials WHERE id=? AND is_active=1',[$rid]);
  if(!$rm) continue;
  execq('INSERT INTO bom_items(bom_id,raw_material_id,qty,unit) VALUES(?,?,?,?)',[$bomId,$rid,$qty,$rm['unit']??'']);
  $saved++;
 }
 return $saved;
}
function call_store_api(array $store,string $path,array $payload=[],string $method='POST'){
 $url=rtrim($store['api_base_url'],'/').'/'.ltrim($path,'/'); $ch=curl_init($url); $headers=['Accept: application/json','Content-Type: application/json']; if(!empty($store['api_token'])) $headers[]='Authorization: Bearer '.$store['api_token']; curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_NOSIGNAL=>1,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3]); if($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE));} $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); curl_close($ch); return [$code,$body,$err];
}
function store_api_message(int $code, string $body, string $err, string $endpoint): string{
 if($err!=='') return 'Gagal menghubungi API toko: '.$err;
 $json=json_decode(trim($body),true); $detail='';
 if(is_array($json)) $detail=(string)($json['message']??$json['error']??$json['status']??($json['ok']??''));
 if($detail==='') $detail=trim(strip_tags(substr($body,0,180)));
 if($code===401) return 'Token API toko tidak valid. Pastikan token dari menu Admin → API Dapur di toko sudah ditempel di Dapur → Toko & API. Endpoint: '.$endpoint.($detail!==''?' - '.$detail:'');
 if($code===403) return 'Token API toko aktif tetapi permission ditolak. Endpoint: '.$endpoint.($detail!==''?' - '.$detail:'');
 if($code===404) return 'Endpoint API Dapur di toko tidak ditemukan: '.$endpoint.'. Pastikan patch API Dapur di toko sudah terpasang.';
 return 'HTTP '.$code.($detail!==''?' - '.$detail:'');
}
function pick_store_products(array $json): array{
 $items=[];
 if(isset($json['products'])&&is_array($json['products'])) $items=$json['products'];
 elseif(isset($json['data'])&&is_array($json['data'])) $items=(isset($json['data']['products'])&&is_array($json['data']['products']))?$json['data']['products']:$json['data'];
 elseif((function_exists('array_is_list')?array_is_list($json):array_keys($json)===range(0,count($json)-1))) $items=$json;
 return array_values(array_filter($items,'is_array'));
}
function can_stock_opname(): bool { return is_owner() || role_key()==='admin_dapur'; }
function api_log_event(?int $storeId, string $endpoint, string $direction, string $status, string $message, $payload=null): void {
 if(!table_exists('api_logs')) return;
 $json=null;
 if($payload!==null){ $json=is_string($payload)?$payload:json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
 execq('INSERT INTO api_logs(store_id,endpoint,direction,status,message,payload_json) VALUES(?,?,?,?,?,?)',[$storeId,substr($endpoint,0,180),$direction,substr($status,0,40),$message,$json]);
}
function short_text($v, int $len=600): string { $t=trim((string)$v); return strlen($t)>$len?substr($t,0,$len).'...':$t; }
function response_payload(int $code, string $body, string $err): array { return ['http_code'=>$code,'curl_error'=>$err,'response_preview'=>short_text($body,1200)]; }
function build_transfer_payload(array $store, int $saleId): array {
 $h=one('SELECT * FROM kitchen_sales_headers WHERE id=?',[$saleId]);
 if(!$h) throw new RuntimeException('Transfer tidak ditemukan.');
 $items=[];
 foreach(all('SELECT i.*,fp.name,fp.sku,fp.source_product_id,fp.unit default_unit FROM kitchen_sales_items i JOIN finished_products fp ON fp.id=i.finished_product_id WHERE i.sale_id=? ORDER BY i.id',[$saleId]) as $r){
  $map=one('SELECT * FROM finished_product_store_mappings WHERE finished_product_id=? AND store_id=? AND is_active=1',[(int)$r['finished_product_id'],(int)$store['id']]);
  // Mapping cabang tidak wajib. Bila belum ada, toko/cabang akan mencocokkan dari SKU/nama atau membuat produk otomatis saat transfer diterima.
  $storeProductId=(string)($map['store_product_id']??'');
  $sku=(string)($map['store_sku']??$r['sku']??'');
  if($sku==='') $sku='DAPUR-FP-'.(int)$r['finished_product_id'];
  $name=(string)($map['store_product_name']??$r['name']??'');
  $items[]=['store_product_id'=>$storeProductId,'sku'=>$sku,'name'=>$name,'qty'=>(float)$r['qty'],'unit'=>$r['unit']?:$r['default_unit'],'transfer_price'=>(float)$r['transfer_price']];
 }
 return ['store_code'=>$store['store_code'],'source'=>'DAPUR_ADENA','transfer_no'=>$h['sale_no'],'transfer_date'=>$h['sale_date'],'items'=>$items,'notes'=>$h['notes']??''];
}
function send_kitchen_transfer(array $store, array $payload): array {
 $endpoint='api/v1/kitchen/receive-transfer.php';
 [$c,$b,$e]=call_store_api($store,$endpoint,$payload,'POST');
 $json=json_decode((string)$b,true);
 $ok=($e==='' && $c>=200 && $c<300 && is_array($json) && !empty($json['ok']));
 $remoteStatus=is_array($json)?(string)($json['status']??(!empty($json['duplicate'])?'duplicate':'')):'';
 $message=$ok?($json['message']??'Transfer diterima API toko.'):store_api_message((int)$c,(string)$b,(string)$e,$endpoint);
 return ['ok'=>$ok,'http_code'=>(int)$c,'body'=>(string)$b,'curl_error'=>(string)$e,'json'=>$json,'remote_status'=>$remoteStatus,'message'=>$message,'endpoint'=>$endpoint];
}
function next_opname_no(): string { return next_no('OPN','stock_opname_headers','opname_no'); }
function ensure_stock_opname_tables(): void {
 db()->exec("CREATE TABLE IF NOT EXISTS stock_opname_headers (id BIGINT AUTO_INCREMENT PRIMARY KEY, opname_no VARCHAR(60) UNIQUE NOT NULL, opname_date DATE NOT NULL, item_type VARCHAR(20) NOT NULL, total_items INT NOT NULL DEFAULT 0, notes TEXT NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 db()->exec("CREATE TABLE IF NOT EXISTS stock_opname_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, opname_id BIGINT NOT NULL, item_type VARCHAR(20) NOT NULL, item_id INT NOT NULL, item_name VARCHAR(180) NULL, system_qty DECIMAL(18,4) NOT NULL DEFAULT 0, physical_qty DECIMAL(18,4) NOT NULL DEFAULT 0, difference_qty DECIMAL(18,4) NOT NULL DEFAULT 0, unit VARCHAR(40) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY idx_opname_id(opname_id), KEY idx_item(item_type,item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function wants_ajax_response(): bool {
 return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest' || stripos((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')!==false;
}
function json_response(array $payload,int $status=200): never {
 if(ob_get_level()>0) { @ob_clean(); }
 http_response_code($status);
 header('Content-Type: application/json; charset=utf-8');
 echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 exit;
}
function finish_store_test(bool $ok,string $message,array $extra=[]): void {
 if(wants_ajax_response()) json_response(array_merge(['ok'=>$ok,'message'=>$message],$extra),$ok?200:422);
 flash($message,$ok?'ok':'err');
 redirect('?page=stores');
}
$f=flash();
?><!doctype html><html><head><meta charset="utf-8"><title>Dapur Adena</title><link rel="stylesheet" href="../assets/app.css?v=20260623f"><script src="../assets/app.js?v=20260623f" defer></script></head><body><div class="app-shell"><aside class="sidebar"><div class="brand">Dapur Adena</div><div class="brand-sub">Produksi • BOM • Multi Toko</div><nav class="nav"><?php foreach($menus as $k=>$m){ if(($m[3]??'')==='owner' && !is_owner()) continue; if(can($m[2])) echo '<a class="'.($page===$k?'active':'').'" href="?page='.e($k).'"><span>'.e($m[1]).'</span> '.e($m[0]).'</a>'; } ?><a href="../logout.php">⎋ Logout</a></nav></aside><main class="main"><div class="topbar"><div><strong><?=e($menus[$page][0])?></strong><div class="muted small">Login: <?=e($u['name']??'')?></div></div></div><?php if($f): ?><div class="notice <?=e($f[1])?>"><?=e($f[0])?></div><?php endif; ?><div class="card">
<?php
if($page==='dashboard'){
 h2('Dashboard Dapur'); $stats=[['Bahan baku',db()->query('SELECT COUNT(*) FROM raw_materials')->fetchColumn()],['Produk jadi',db()->query('SELECT COUNT(*) FROM finished_products')->fetchColumn()],['Produksi',db()->query('SELECT COUNT(*) FROM production_headers')->fetchColumn()],['Penjualan ke toko',db()->query('SELECT COUNT(*) FROM kitchen_sales_headers')->fetchColumn()],['Kegiatan pegawai',db()->query('SELECT COUNT(*) FROM employee_activities')->fetchColumn()]]; echo '<div class="grid">'; foreach($stats as $s) echo '<div class="card"><div class="muted">'.e($s[0]).'</div><div class="stat">'.e($s[1]).'</div></div>'; echo '</div>';
}
elseif($page==='stores'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=$_POST['act']??'';
  if($act==='save'){
   $id=(int)($_POST['id']??0);
   $params=[postval('store_code'),postval('store_name'),postval('api_base_url'),postval('api_token'),isset($_POST['is_active'])?1:0,postval('notes')];
   if($id>0){
    $params[]=$id;
    execq('UPDATE stores SET store_code=?,store_name=?,api_base_url=?,api_token=?,is_active=?,notes=? WHERE id=?',$params);
    flash('Toko/API diperbarui.');
   }else{
    execq('INSERT INTO stores(store_code,store_name,api_base_url,api_token,is_active,notes) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),api_base_url=VALUES(api_base_url),api_token=VALUES(api_token),is_active=VALUES(is_active),notes=VALUES(notes)',$params);
    flash('Toko/API disimpan.');
   }
   redirect('?page=stores');
  }
  if($act==='delete'){
   $id=(int)($_POST['id']??0);
   $s=one('SELECT * FROM stores WHERE id=?',[$id]);
   if(!$s){ flash('Toko tidak ditemukan.','err'); redirect('?page=stores'); }
   $refs=0;
   $refs+=(int)one('SELECT COUNT(*) c FROM finished_products WHERE source_store_id=?',[$id])['c'];
   $refs+=(int)one('SELECT COUNT(*) c FROM finished_product_store_mappings WHERE store_id=?',[$id])['c'];
   $refs+=(int)one('SELECT COUNT(*) c FROM product_import_logs WHERE store_id=?',[$id])['c'];
   $refs+=(int)one('SELECT COUNT(*) c FROM kitchen_sales_headers WHERE store_id=?',[$id])['c'];
   $refs+=(int)one('SELECT COUNT(*) c FROM api_logs WHERE store_id=?',[$id])['c'];
   if($refs>0){
    execq('UPDATE stores SET is_active=0 WHERE id=?',[$id]);
    flash('Toko punya histori transaksi/import/API, jadi dihapus aman dengan status Nonaktif.');
   }else{
    execq('DELETE FROM stores WHERE id=?',[$id]);
    flash('Toko/API dihapus.');
   }
   redirect('?page=stores');
  }
  if(in_array($act,['test','test_ping','test_products','test_transfer'],true)){
   try {
   $s=one('SELECT * FROM stores WHERE id=?',[(int)$_POST['id']]);
   if(!$s){ finish_store_test(false,'Toko tidak ditemukan.'); }
   if($act==='test' || $act==='test_products'){
    $endpoint='api/v1/kitchen/products.php';
    [$c,$b,$e]=call_store_api($s,$endpoint,[],'GET');
    $json=json_decode(trim((string)$b),true);
    if($c>=200&&$c<300&&is_array($json)&&!empty($json['ok'])){
     $items=pick_store_products($json);
     $total=(int)($json['total']??count($items));
     api_log_event((int)$s['id'],$endpoint,'out','product_test_ok','Test produk sukses. Produk terbaca: '.$total,response_payload((int)$c,(string)$b,(string)$e));
     finish_store_test(true,'Test produk sukses. Token kitchen valid. Produk terbaca: '.$total.' item.');
    }else{
     $msg=store_api_message((int)$c,(string)$b,(string)$e,$endpoint);
     api_log_event((int)$s['id'],$endpoint,'out','product_test_failed',$msg,response_payload((int)$c,(string)$b,(string)$e));
     finish_store_test(false,'Test produk gagal. '.$msg);
    }
   }
   if($act==='test_ping'){
    $endpoint='api/v1/kitchen/ping.php';
    [$c,$b,$e]=call_store_api($s,$endpoint,[],'GET');
    $json=json_decode(trim((string)$b),true);
    if($c>=200&&$c<300&&is_array($json)&&!empty($json['ok'])){
     api_log_event((int)$s['id'],$endpoint,'out','ping_ok','Ping API receiver sukses.',response_payload((int)$c,(string)$b,(string)$e));
     finish_store_test(true,'Ping API receiver sukses. Endpoint transfer tersedia.');
    }else{
     $msg=store_api_message((int)$c,(string)$b,(string)$e,$endpoint);
     api_log_event((int)$s['id'],$endpoint,'out','ping_failed',$msg,response_payload((int)$c,(string)$b,(string)$e));
     finish_store_test(false,'Ping API gagal. '.$msg);
    }
   }
   if($act==='test_transfer'){
    $map=one('SELECT m.*,fp.name,fp.unit,fp.transfer_price FROM finished_product_store_mappings m JOIN finished_products fp ON fp.id=m.finished_product_id WHERE m.store_id=? AND m.is_active=1 AND fp.is_active=1 ORDER BY m.id LIMIT 1',[(int)$s['id']]);
    if(!$map){
     $msg='Belum ada mapping produk aktif untuk toko ini. Import produk dulu atau pastikan produk sudah memiliki mapping toko.';
     api_log_event((int)$s['id'],'api/v1/kitchen/receive-transfer.php','out','transfer_test_failed',$msg,['reason'=>'mapping_missing']);
     finish_store_test(false,'Test transfer gagal. '.$msg);
    }
    $payload=['dry_run'=>true,'store_code'=>$s['store_code'],'source'=>'DAPUR_ADENA','transfer_no'=>'TEST-'.date('YmdHis').'-'.(int)$s['id'],'transfer_date'=>date('Y-m-d'),'items'=>[['store_product_id'=>(string)$map['store_product_id'],'sku'=>(string)($map['store_sku']??''),'name'=>(string)($map['store_product_name']??$map['name']),'qty'=>1,'unit'=>(string)($map['unit']??'pcs'),'transfer_price'=>(float)($map['transfer_price']??0)]],'notes'=>'Dry-run test transfer dari Dapur. Tidak mengubah stok.'];
    $res=send_kitchen_transfer($s,$payload);
    if($res['ok']){
     api_log_event((int)$s['id'],$res['endpoint'],'out','transfer_test_ok',$res['message'],['request'=>$payload,'response'=>response_payload((int)$res['http_code'],(string)$res['body'],(string)$res['curl_error'])]);
     finish_store_test(true,'Test transfer sukses. Endpoint transfer valid dan dry-run tidak mengubah stok.');
    }else{
     api_log_event((int)$s['id'],$res['endpoint'],'out','transfer_test_failed',$res['message'],['request'=>$payload,'response'=>response_payload((int)$res['http_code'],(string)$res['body'],(string)$res['curl_error'])]);
     finish_store_test(false,'Test transfer gagal. '.$res['message']);
    }
   }
   } catch(Throwable $e) {
    api_log_event((int)($_POST['id']??0),'api_test','out','fatal_error',$e->getMessage(),['exception'=>get_class($e)]);
    finish_store_test(false,'Aksi API gagal tanpa membuat halaman blank: '.$e->getMessage());
   }
  }
 }
 $editId=(int)($_GET['edit']??0);
 $edit=$editId>0?one('SELECT * FROM stores WHERE id=?',[$editId]):null;
 h2('Multi Toko / Setting API');
 echo '<form method="post" class="form-grid compact-form store-form">'.csrf_field().'<input type="hidden" name="act" value="save"><input type="hidden" name="id" value="'.(int)($edit['id']??0).'"><p><label>Kode Toko<input name="store_code" value="'.e($edit['store_code']??'').'" required></label></p><p><label>Nama Toko<input name="store_name" value="'.e($edit['store_name']??'').'" required></label></p><p><label>Base URL API Toko<input name="api_base_url" value="'.e($edit['api_base_url']??'').'" placeholder="https://toko.adena.co.id" required></label></p><p><label>Token API Toko<input name="api_token" value="'.e($edit['api_token']??'').'"></label></p><p><label>Catatan<input name="notes" value="'.e($edit['notes']??'').'"></label></p><p><label class="check-inline"><input type="checkbox" name="is_active" '.((!$edit||!empty($edit['is_active']))?'checked':'').' > Aktif</label></p><p class="actions store-save-actions"><button class="btn">'.($edit?'Update':'Simpan').'</button>'.($edit?' <a class="btn light" href="?page=stores">Batal</a>':'').'</p></form>';
 echo '<div id="store-api-status" class="store-api-status" hidden></div>';
 echo '<div class="table-scroll"><table class="compact-table stores-table"><tr><th>Kode</th><th>Nama</th><th>API</th><th>Status</th><th>Aksi</th></tr>'; foreach(all('SELECT * FROM stores ORDER BY store_name') as $r){echo '<tr><td>'.e($r['store_code']).'</td><td>'.e($r['store_name']).'</td><td class="api-url-cell">'.e($r['api_base_url']).'</td><td>'.($r['is_active']?'Aktif':'Nonaktif').'</td><td><div class="actions"><form method="post" data-store-api-test="1">'.csrf_field().'<input type="hidden" name="act" value="test_ping"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light" data-loading-text="Ping...">Ping</button></form><form method="post" data-store-api-test="1">'.csrf_field().'<input type="hidden" name="act" value="test_products"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light" data-loading-text="Test...">Test Produk</button></form><form method="post" data-store-api-test="1">'.csrf_field().'<input type="hidden" name="act" value="test_transfer"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light" data-loading-text="Test...">Test Transfer</button></form><a class="btn light" href="?page=stores&edit='.(int)$r['id'].'">Edit</a><form method="post" onsubmit="return confirm(&quot;Hapus/nonaktifkan toko ini?&quot;)">'.csrf_field().'<input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; } echo '</table></div>';
}
elseif($page==='raw'){
 if($_SERVER['REQUEST_METHOD']==='POST'){execq('INSERT INTO raw_materials(code,name,category,unit,min_stock,last_cost,is_active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),unit=VALUES(unit),min_stock=VALUES(min_stock),last_cost=VALUES(last_cost)',[postval('code')?:null,postval('name'),postval('category'),postval('unit','pcs'),(float)postval('min_stock','0'),(float)postval('last_cost','0')]); flash('Bahan baku disimpan.'); redirect('?page=raw');}
 h2('Bahan Baku'); echo '<form method="post" class="form-grid">'.csrf_field().'<p><label>Kode<input name="code"></label></p><p><label>Nama<input name="name" required></label></p><p><label>Kategori<input name="category"></label></p><p><label>Satuan<input name="unit" value="gram"></label></p><p><label>Min Stok<input name="min_stock" type="number" step="0.0001"></label></p><p><label>Last Cost<input name="last_cost" type="number" step="0.01"></label></p><p><button class="btn">Simpan</button></p></form>'; echo '<table><tr><th>Nama</th><th>Satuan</th><th>Stok</th><th>Last cost</th></tr>'; foreach(all('SELECT * FROM raw_materials ORDER BY name') as $r) echo '<tr><td>'.e($r['name']).'</td><td>'.e($r['unit']).'</td><td>'.dec(stock_qty('raw',(int)$r['id'])).'</td><td>'.rupiah($r['last_cost']).'</td></tr>'; echo '</table>';
}
elseif($page==='finished'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['act']??'')==='manual'){execq('INSERT INTO finished_products(code,sku,name,category,unit,sale_price,transfer_price,is_active) VALUES(?,?,?,?,?,?,?,1)',[postval('code'),postval('sku'),postval('name'),postval('category'),postval('unit','pack'),0,(float)postval('transfer_price','0')]); flash('Produk jadi disimpan.'); redirect('?page=finished');}
  if(($_POST['act']??'')==='update_finished'){ $id=(int)($_POST['id']??0); $existing=$id>0?one('SELECT * FROM finished_products WHERE id=?',[$id]):null; if(!$existing){flash('Produk jadi tidak ditemukan.','err');redirect('?page=finished');} execq('UPDATE finished_products SET code=?, sku=?, category=?, unit=?, transfer_price=?, is_active=?, updated_at=NOW() WHERE id=?',[postval('code'),postval('sku'),postval('category'),postval('unit','pack'),(float)postval('transfer_price','0'),isset($_POST['is_active'])?1:0,$id]); flash('Produk jadi diupdate. Nama tetap dikunci agar sama dengan toko.'); redirect('?page=finished');}
  if(($_POST['act']??'')==='delete_finished'){ if(!can_manage_finished_delete()){ http_response_code(403); die('Akses ditolak.'); } $id=(int)($_POST['id']??0); $existing=$id>0?one('SELECT * FROM finished_products WHERE id=?',[$id]):null; if(!$existing){flash('Produk jadi tidak ditemukan.','err');redirect('?page=finished');} $refs=finished_product_ref_count($id); if($refs>0){ execq('UPDATE finished_products SET is_active=0, updated_at=NOW() WHERE id=?',[$id]); if(table_exists('finished_product_store_mappings')) execq('UPDATE finished_product_store_mappings SET is_active=0 WHERE finished_product_id=?',[$id]); flash('Produk sudah memiliki histori transaksi/produksi/stok, jadi disembunyikan dari daftar.'); } else { if(table_exists('finished_product_store_mappings')) execq('DELETE FROM finished_product_store_mappings WHERE finished_product_id=?',[$id]); execq('DELETE FROM finished_products WHERE id=?',[$id]); flash('Produk jadi dihapus permanen.'); } redirect('?page=finished'); }
  if(($_POST['act']??'')==='import'){ $store=one('SELECT * FROM stores WHERE id=?',[(int)$_POST['store_id']]); [$c,$body,$err]=call_store_api($store,'api/v1/kitchen/products.php',[],'GET'); if($err!==''||$c<200||$c>=300){flash('Import produk gagal. '.store_api_message((int)$c,(string)$body,(string)$err,'api/v1/kitchen/products.php'),'err'); redirect('?page=finished');} $json=json_decode((string)$body,true); $items=is_array($json)?pick_store_products($json):[]; $n=0; foreach($items as $it){ if(!is_array($it))continue; $pid=(string)($it['id']??$it['product_id']??''); $name=(string)($it['name']??$it['product_name']??''); if($pid===''||$name==='')continue; execq('INSERT INTO finished_products(code,sku,name,category,unit,sale_price,transfer_price,image_path,source_store_id,source_product_id,source_payload_json,is_active,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE code=VALUES(code),sku=VALUES(sku),name=VALUES(name),category=VALUES(category),unit=VALUES(unit),image_path=VALUES(image_path),source_payload_json=VALUES(source_payload_json),updated_at=NOW()',[(string)($it['code']??$it['sku']??''),(string)($it['sku']??''),$name,(string)($it['category']??''),(string)($it['base_unit']??$it['unit']??'pack'),0,0,(string)($it['image_path']??''),(int)$store['id'],$pid,json_encode($it,JSON_UNESCAPED_UNICODE),1]); $fp=(int)db()->lastInsertId(); if($fp===0){$fp=(int)one('SELECT id FROM finished_products WHERE source_store_id=? AND source_product_id=?',[(int)$store['id'],$pid])['id'];} execq('INSERT INTO finished_product_store_mappings(finished_product_id,store_id,store_product_id,store_sku,store_product_name,store_price,is_active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE store_product_id=VALUES(store_product_id),store_sku=VALUES(store_sku),store_product_name=VALUES(store_product_name),is_active=1',[$fp,(int)$store['id'],$pid,(string)($it['sku']??''),$name,0]); $n++; } execq('INSERT INTO product_import_logs(store_id,total_imported,message,created_by) VALUES(?,?,?,?)',[(int)$store['id'],$n,'Import via API', (int)($u['id']??0)]); flash('Import produk selesai: '.$n.' item.'); redirect('?page=finished'); }
 }
 h2('Produk Jadi / Finished Product');
 $activeStores=all('SELECT * FROM stores WHERE is_active=1 ORDER BY store_name');
 $finishedRows=all('SELECT fp.*, s.store_name FROM finished_products fp LEFT JOIN stores s ON s.id=fp.source_store_id WHERE fp.is_active=1 ORDER BY fp.name');
 echo '<div class="finished-toolbar card"><div class="finished-filter-grid"><p class="finished-search-field"><label>Search<input type="search" data-finished-search placeholder="Cari produk / SKU / sumber..."></label></p><p><label>Sumber<select data-finished-source><option value="">Semua sumber</option><option value="manual">Manual</option>'; foreach($activeStores as $s) echo '<option value="store:'.(int)$s['id'].'">'.e($s['store_name']).'</option>'; echo '</select></label></p><p><label>Status stok<select data-finished-stock><option value="">Semua stok</option><option value="available">Stok tersedia</option><option value="empty">Stok kosong</option></select></label></p><div class="finished-toolbar-actions"><button class="btn light" type="button" data-finished-filter-reset>Reset</button><button class="btn secondary" type="button" data-finished-import-open'.(count($activeStores)<1?' disabled':'').'>Impor Produk</button></div></div></div>';
 echo '<div class="finished-table-wrap"><table class="finished-products-table" data-finished-table><thead><tr><th class="col-product">Produk</th><th class="col-sku">SKU</th><th class="col-price">Harga Jual Dapur</th><th class="col-stock">Stok Dapur</th><th class="col-source">Sumber</th><th class="col-action">Aksi</th></tr></thead><tbody>'; foreach($finishedRows as $r){ $stock=(float)stock_qty('finished',(int)$r['id']); $sourceLabel=(string)($r['store_name']??'Manual'); $sourceKey=!empty($r['source_store_id'])?'store:'.(int)$r['source_store_id']:'manual'; $isActive=!empty($r['is_active'])?'1':'0'; $searchText=trim(($r['name']??'').' '.($r['sku']??'').' '.($r['code']??'').' '.($r['category']??'').' '.($r['unit']??'').' '.$sourceLabel.' '.rupiah($r['transfer_price']).' '.dec($stock)); echo '<tr data-finished-row data-search="'.e(strtolower($searchText)).'" data-source="'.e($sourceKey).'" data-stock="'.($stock>0?'available':'empty').'" data-active="'.$isActive.'"><td class="product-name-cell">'.e($r['name']).'</td><td>'.e($r['sku']).'</td><td>'.rupiah($r['transfer_price']).'</td><td>'.dec($stock).'</td><td>'.e($sourceLabel).'</td><td><div class="actions"><button type="button" class="btn light" data-finished-edit data-id="'.(int)$r['id'].'" data-code="'.e($r['code']??'').'" data-sku="'.e($r['sku']??'').'" data-name="'.e($r['name']??'').'" data-category="'.e($r['category']??'').'" data-unit="'.e($r['unit']??'pack').'" data-transfer-price="'.e((string)($r['transfer_price']??'0')).'" data-active="'.$isActive.'">Edit</button>'.(can_manage_finished_delete()?'<form method="post" onsubmit="return confirm(\'Hapus produk ini? Bila sudah ada histori, produk akan di-hide dari daftar.\')">'.csrf_field().'<input type="hidden" name="act" value="delete_finished"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form>':'').'</div></td></tr>'; } echo '<tr data-finished-empty hidden><td colspan="6" class="muted">Tidak ada produk yang sesuai filter.</td></tr></tbody></table></div>';
 echo '<div class="modal-backdrop" data-finished-import-modal hidden><div class="modal-card finished-import-modal" role="dialog" aria-modal="true" aria-labelledby="finished-import-title"><div class="modal-head"><div><h3 id="finished-import-title">Impor Produk</h3><div class="muted small">Ambil produk dari toko terhubung. Harga jual toko tidak diimpor.</div></div><button type="button" class="modal-close" data-finished-import-close aria-label="Tutup">&times;</button></div><form method="post" class="finished-import-form product-import-form" data-product-import="1" data-import-url="import_products_ajax.php">'.csrf_field().'<input type="hidden" name="act" value="import"><p><label>Toko<select name="store_id" required>'; if(count($activeStores)<1){ echo '<option value="">Belum ada toko aktif</option>'; } foreach($activeStores as $s) echo '<option value="'.(int)$s['id'].'">'.e($s['store_name']).'</option>'; echo '</select></label></p><div class="import-progress" id="product-import-progress" hidden><div class="import-progress-top"><strong>Status import</strong><span data-import-percent>0%</span></div><div class="import-progress-track"><div class="import-progress-bar" data-import-bar></div></div><div class="muted small" data-import-status>Menunggu proses import...</div></div><div class="modal-actions"><button class="btn secondary" type="submit"'.(count($activeStores)<1?' disabled':'').'>Impor Produk</button><button class="btn light" type="button" data-finished-import-close>Batal</button></div></form></div></div>';
 echo '<div class="modal-backdrop" data-finished-modal hidden><div class="modal-card finished-edit-modal" role="dialog" aria-modal="true" aria-labelledby="finished-edit-title"><div class="modal-head"><div><h3 id="finished-edit-title">Edit Produk Jadi</h3><div class="muted small">Nama produk dikunci agar tetap sama dengan toko.</div></div><button type="button" class="modal-close" data-finished-modal-close aria-label="Tutup">&times;</button></div><form method="post" class="finished-edit-grid">'.csrf_field().'<input type="hidden" name="act" value="update_finished"><input type="hidden" name="id" data-finished-field="id"><p class="field-wide"><label>Nama<input name="name" data-finished-field="name" readonly></label></p><p><label>Kode<input name="code" data-finished-field="code"></label></p><p><label>SKU<input name="sku" data-finished-field="sku"></label></p><p><label>Kategori<input name="category" data-finished-field="category"></label></p><p><label>Satuan<input name="unit" data-finished-field="unit"></label></p><p class="field-wide"><label>Harga Jual Dapur / Harga Pembelian Toko<input name="transfer_price" type="number" step="0.01" data-finished-field="transfer_price"></label><span class="muted">Dipakai sebagai harga pembelian/cost di toko saat transfer stok.</span></p><p class="finished-active-field"><label><input type="checkbox" name="is_active" data-finished-field="is_active"> Aktif</label></p><div class="modal-actions field-wide"><button class="btn" type="submit">Update</button><button class="btn light" type="button" data-finished-modal-close>Batal</button></div></form></div></div>';
}

elseif($page==='finished_hidden'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['act']??'')==='restore_finished'){ if(!can_manage_finished_delete()){ http_response_code(403); die('Akses ditolak.'); } $id=(int)($_POST['id']??0); $existing=$id>0?one('SELECT * FROM finished_products WHERE id=?',[$id]):null; if(!$existing){flash('Produk jadi tidak ditemukan.','err');redirect('?page=finished_hidden');} execq('UPDATE finished_products SET is_active=1, updated_at=NOW() WHERE id=?',[$id]); if(table_exists('finished_product_store_mappings')) execq('UPDATE finished_product_store_mappings SET is_active=1 WHERE finished_product_id=?',[$id]); flash('Produk dikembalikan ke daftar Produk Jadi.'); redirect('?page=finished_hidden'); }
 }
 h2('Hide Produk Jadi');
 echo '<p class="muted">Produk di halaman ini disembunyikan dari daftar utama, tetapi histori transaksi/produksi tetap aman.</p>';
 echo '<table><tr><th>Produk</th><th>SKU</th><th>Harga Jual Dapur</th><th>Sumber</th><th>Aksi</th></tr>';
 foreach(all('SELECT fp.*, s.store_name FROM finished_products fp LEFT JOIN stores s ON s.id=fp.source_store_id WHERE fp.is_active=0 ORDER BY fp.name') as $r){ echo '<tr><td>'.e($r['name']).'</td><td>'.e($r['sku']).'</td><td>'.rupiah($r['transfer_price']).'</td><td>'.e($r['store_name']??'Manual').'</td><td>'.(can_manage_finished_delete()?'<form method="post">'.csrf_field().'<input type="hidden" name="act" value="restore_finished"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light">Aktifkan Lagi</button></form>':'-').'</td></tr>'; }
 echo '</table>';
}
elseif($page==='purchases'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $pid=0; $no=next_no('PB','purchase_headers','purchase_no'); execq('INSERT INTO purchase_headers(purchase_no,purchase_date,supplier_name,status,notes,created_by) VALUES(?,?,?,?,?,?)',[$no,postval('purchase_date',date('Y-m-d')),postval('supplier_name'),'posted',postval('notes'),(int)($u['id']??0)]); $pid=(int)db()->lastInsertId(); $total=0; foreach($_POST['raw_material_id']??[] as $i=>$rid){$rid=(int)$rid;$qty=(float)($_POST['qty'][$i]??0);$cost=(float)($_POST['unit_cost'][$i]??0); if($rid<=0||$qty<=0)continue; $rm=one('SELECT unit FROM raw_materials WHERE id=?',[$rid]); $sub=$qty*$cost; $total+=$sub; execq('INSERT INTO purchase_items(purchase_id,raw_material_id,qty,unit,unit_cost,subtotal) VALUES(?,?,?,?,?,?)',[$pid,$rid,$qty,$rm['unit']??'', $cost,$sub]); add_ledger('raw',$rid,'purchase','purchase_headers',$pid,$qty,0,$cost,$no,(int)($u['id']??0)); execq('UPDATE raw_materials SET last_cost=? WHERE id=?',[$cost,$rid]); } execq('UPDATE purchase_headers SET total_amount=?, posted_at=NOW() WHERE id=?',[$total,$pid]); flash('Pembelian diposting: '.$no); redirect('?page=purchases'); }
 h2('Pembelian Bahan Baku'); $rms=all('SELECT * FROM raw_materials WHERE is_active=1 ORDER BY name'); echo '<form method="post">'.csrf_field().'<div class="form-grid"><p><label>Tanggal<input name="purchase_date" type="date" value="'.date('Y-m-d').'" required></label></p><p><label>Supplier<input name="supplier_name"></label></p><p><label>Catatan<input name="notes"></label></p></div><table><tr><th>Bahan</th><th>Qty</th><th>Unit Cost</th></tr>'; for($i=0;$i<5;$i++){ echo '<tr><td><select name="raw_material_id[]"><option value="">-</option>'; foreach($rms as $rm) echo '<option value="'.(int)$rm['id'].'">'.e($rm['name']).'</option>'; echo '</select></td><td><input name="qty[]" type="number" step="0.0001"></td><td><input name="unit_cost[]" type="number" step="0.01"></td></tr>'; } echo '</table><p><button class="btn">Posting Pembelian</button></p></form>';
 echo '<h3>Riwayat</h3><table><tr><th>No</th><th>Tanggal</th><th>Supplier</th><th>Total</th></tr>'; foreach(all('SELECT * FROM purchase_headers ORDER BY id DESC LIMIT 20') as $r) echo '<tr><td>'.e($r['purchase_no']).'</td><td>'.e($r['purchase_date']).'</td><td>'.e($r['supplier_name']).'</td><td>'.rupiah($r['total_amount']).'</td></tr>'; echo '</table>';
}
elseif($page==='bom'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=$_POST['act']??'create_bom';
  if($act==='create_bom' || $act==='update_bom'){
   $id=(int)($_POST['id']??0);
   $code=postval('bom_code');
   $finishedId=(int)($_POST['finished_product_id']??0);
   $yield=(float)postval('yield_qty','1');
   if($yield<=0){ flash('Yield Qty harus lebih dari 0.','err'); redirect('?page=bom'.($id>0?'&edit='.$id:'')); }
   $fp=one('SELECT * FROM finished_products WHERE id=? AND is_active=1',[$finishedId]);
   if(!$fp){ flash('Produk jadi tidak valid atau sedang hide.','err'); redirect('?page=bom'.($id>0?'&edit='.$id:'')); }
   try{
    db()->beginTransaction();
    if($act==='update_bom'){
     $bom=one('SELECT * FROM bom_headers WHERE id=?',[$id]);
     if(!$bom){ throw new Exception('BOM tidak ditemukan.'); }
     execq('UPDATE bom_headers SET bom_code=?, finished_product_id=?, yield_qty=?, notes=?, is_active=1 WHERE id=?',[$code?:$bom['bom_code'],$finishedId,$yield,postval('notes'),$id]);
     $bid=$id;
    }else{
     execq('INSERT INTO bom_headers(bom_code,finished_product_id,yield_qty,notes,is_active) VALUES(?,?,?,?,1)',[$code?:next_no('BOM','bom_headers','bom_code'),$finishedId,$yield,postval('notes')]);
     $bid=(int)db()->lastInsertId();
    }
    $itemCount=save_bom_items($bid,$_POST['raw_material_id']??[],$_POST['qty']??[]);
    if($itemCount<1){ throw new Exception('Minimal 1 bahan baku wajib diisi.'); }
    db()->commit();
    flash($act==='update_bom'?'BOM diupdate.':'BOM dibuat.');
    redirect('?page=bom');
   }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); flash('Gagal menyimpan BOM: '.$e->getMessage(),'err'); redirect('?page=bom'.($id>0?'&edit='.$id:'')); }
  }
  if($act==='delete_bom'){
   $id=(int)($_POST['id']??0); $bom=one('SELECT * FROM bom_headers WHERE id=?',[$id]);
   if(!$bom){ flash('BOM tidak ditemukan.','err'); redirect('?page=bom'); }
   if(bom_ref_count($id)>0){ execq('UPDATE bom_headers SET is_active=0 WHERE id=?',[$id]); flash('BOM sudah pernah dipakai produksi, jadi disembunyikan dari daftar.'); }
   else { execq('DELETE FROM bom_items WHERE bom_id=?',[$id]); execq('DELETE FROM bom_headers WHERE id=?',[$id]); flash('BOM dihapus permanen.'); }
   redirect('?page=bom');
  }
 }
 $editId=(int)($_GET['edit']??0); $edit=$editId>0?one('SELECT * FROM bom_headers WHERE id=?',[$editId]):null; $editItems=$edit?all('SELECT * FROM bom_items WHERE bom_id=? ORDER BY id',[(int)$edit['id']]):[];
 h2($edit?'Edit BOM':'BOM Backward Produk Jadi');
 $fps=all('SELECT * FROM finished_products WHERE is_active=1 ORDER BY name'); $rms=all('SELECT * FROM raw_materials WHERE is_active=1 ORDER BY name');
 echo '<form method="post">'.csrf_field().'<input type="hidden" name="act" value="'.($edit?'update_bom':'create_bom').'"><input type="hidden" name="id" value="'.(int)($edit['id']??0).'"><div class="form-grid"><p><label>Kode BOM<input name="bom_code" value="'.e($edit['bom_code']??'').'" placeholder="auto bila kosong"></label></p><p><label>Produk Jadi<select name="finished_product_id">';
 foreach($fps as $fp) echo '<option value="'.(int)$fp['id'].'" '.(((int)($edit['finished_product_id']??0)===(int)$fp['id'])?'selected':'').'>'.e($fp['name']).'</option>';
 echo '</select></label></p><p><label>Yield Qty<input name="yield_qty" type="number" step="0.0001" value="'.e((string)($edit['yield_qty']??'1')).'"></label></p><p><label>Catatan<input name="notes" value="'.e($edit['notes']??'').'"></label></p></div><table><tr><th>Bahan Baku</th><th>Qty kebutuhan</th></tr>';
 $rows=max(8,count($editItems));
 for($i=0;$i<$rows;$i++){ $cur=$editItems[$i]??[]; echo '<tr><td><select name="raw_material_id[]"><option value="">-</option>'; foreach($rms as $rm) echo '<option value="'.(int)$rm['id'].'" '.(((int)($cur['raw_material_id']??0)===(int)$rm['id'])?'selected':'').'>'.e($rm['name']).'</option>'; echo '</select></td><td><input name="qty[]" type="number" step="0.0001" value="'.e((string)($cur['qty']??'')).'"></td></tr>'; }
 echo '</table><p class="actions"><button class="btn">'.($edit?'Update BOM':'Simpan BOM').'</button>'.($edit?' <a class="btn light" href="?page=bom">Batal</a>':'').' <a class="btn light" href="?page=bom_hidden">Hide BOM</a></p></form><h3>Daftar BOM Aktif</h3><table><tr><th>Kode</th><th>Produk</th><th>Yield</th><th>Item</th><th>Aksi</th></tr>';
 foreach(all('SELECT b.*,fp.name product_name,(SELECT COUNT(*) FROM bom_items bi WHERE bi.bom_id=b.id) item_count FROM bom_headers b JOIN finished_products fp ON fp.id=b.finished_product_id WHERE b.is_active=1 ORDER BY b.id DESC') as $r){ echo '<tr><td>'.e($r['bom_code']).'</td><td>'.e($r['product_name']).'</td><td>'.dec($r['yield_qty']).'</td><td>'.(int)$r['item_count'].'</td><td><div class="actions"><a class="btn light" href="?page=bom&edit='.(int)$r['id'].'">Edit</a><form method="post" onsubmit="return confirm(&quot;Hapus BOM ini? Bila sudah pernah produksi, BOM akan di-hide.&quot;)">'.csrf_field().'<input type="hidden" name="act" value="delete_bom"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; }
 echo '</table>';
}
elseif($page==='bom_hidden'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['act']??'')==='restore_bom'){
   $id=(int)($_POST['id']??0); $bom=$id>0?one('SELECT * FROM bom_headers WHERE id=?',[$id]):null;
   if(!$bom){ flash('BOM tidak ditemukan.','err'); redirect('?page=bom_hidden'); }
   execq('UPDATE bom_headers SET is_active=1 WHERE id=?',[$id]); flash('BOM dikembalikan ke daftar BOM aktif.'); redirect('?page=bom_hidden');
  }
 }
 h2('Hide BOM');
 echo '<p class="muted">BOM di halaman ini disembunyikan dari produksi, tetapi histori produksi lama tetap aman.</p>';
 echo '<table><tr><th>Kode</th><th>Produk</th><th>Yield</th><th>Produksi Terkait</th><th>Aksi</th></tr>';
 foreach(all('SELECT b.*,fp.name product_name,(SELECT COUNT(*) FROM production_headers p WHERE p.bom_id=b.id) prod_count FROM bom_headers b JOIN finished_products fp ON fp.id=b.finished_product_id WHERE b.is_active=0 ORDER BY b.id DESC') as $r){ echo '<tr><td>'.e($r['bom_code']).'</td><td>'.e($r['product_name']).'</td><td>'.dec($r['yield_qty']).'</td><td>'.(int)$r['prod_count'].'</td><td><div class="actions"><a class="btn light" href="?page=bom&edit='.(int)$r['id'].'">Edit</a><form method="post">'.csrf_field().'<input type="hidden" name="act" value="restore_bom"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light">Aktifkan Lagi</button></form></div></td></tr>'; }
 echo '</table>';
}
elseif($page==='production'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $bom=one('SELECT * FROM bom_headers WHERE id=?',[(int)$_POST['bom_id']]); $qty=(float)postval('qty_produced','0'); if(!$bom||$qty<=0){flash('BOM/qty tidak valid','err');redirect('?page=production');} $factor=$qty/(float)$bom['yield_qty']; foreach(all('SELECT * FROM bom_items WHERE bom_id=?',[(int)$bom['id']]) as $bi){$need=(float)$bi['qty']*$factor; if(stock_qty('raw',(int)$bi['raw_material_id'])+0.0001 < $need){flash('Stok bahan tidak cukup untuk produksi.','err');redirect('?page=production');}} $no=next_no('PRD','production_headers','production_no'); execq('INSERT INTO production_headers(production_no,production_date,bom_id,finished_product_id,qty_produced,status,notes,created_by,posted_at) VALUES(?,?,?,?,?,?,?,?,NOW())',[$no,postval('production_date',date('Y-m-d')),(int)$bom['id'],(int)$bom['finished_product_id'],$qty,'posted',postval('notes'),(int)($u['id']??0)]); $pid=(int)db()->lastInsertId(); foreach(all('SELECT bi.*, rm.last_cost FROM bom_items bi JOIN raw_materials rm ON rm.id=bi.raw_material_id WHERE bi.bom_id=?',[(int)$bom['id']]) as $bi){$used=(float)$bi['qty']*$factor; execq('INSERT INTO production_items(production_id,raw_material_id,qty_used,unit,unit_cost) VALUES(?,?,?,?,?)',[$pid,(int)$bi['raw_material_id'],$used,$bi['unit'],(float)$bi['last_cost']]); add_ledger('raw',(int)$bi['raw_material_id'],'production','production_headers',$pid,0,$used,(float)$bi['last_cost'],$no,(int)($u['id']??0));} add_ledger('finished',(int)$bom['finished_product_id'],'production','production_headers',$pid,$qty,0,null,$no,(int)($u['id']??0)); flash('Produksi diposting: '.$no); redirect('?page=production'); }
 h2('Produksi dari BOM'); echo '<form method="post" class="form-grid">'.csrf_field().'<p><label>Tanggal<input name="production_date" type="date" value="'.date('Y-m-d').'"></label></p><p><label>BOM<select name="bom_id">'; foreach(all('SELECT b.*,fp.name product_name FROM bom_headers b JOIN finished_products fp ON fp.id=b.finished_product_id WHERE b.is_active=1 ORDER BY fp.name') as $b) echo '<option value="'.(int)$b['id'].'">'.e($b['bom_code'].' - '.$b['product_name']).'</option>'; echo '</select></label></p><p><label>Qty Produksi<input name="qty_produced" type="number" step="0.0001" required></label></p><p><label>Catatan<input name="notes"></label></p><p><button class="btn">Posting Produksi</button></p></form>'; echo '<table><tr><th>No</th><th>Tanggal</th><th>Produk</th><th>Qty</th></tr>'; foreach(all('SELECT p.*,fp.name product_name FROM production_headers p JOIN finished_products fp ON fp.id=p.finished_product_id ORDER BY p.id DESC LIMIT 30') as $r) echo '<tr><td>'.e($r['production_no']).'</td><td>'.e($r['production_date']).'</td><td>'.e($r['product_name']).'</td><td>'.dec($r['qty_produced']).'</td></tr>'; echo '</table>';
}
elseif($page==='stock'){
 h2('Stok Dapur'); echo '<h3>Bahan Baku</h3><table><tr><th>Nama</th><th>Stok</th><th>Satuan</th></tr>'; foreach(all('SELECT * FROM raw_materials ORDER BY name') as $r) echo '<tr><td>'.e($r['name']).'</td><td>'.dec(stock_qty('raw',(int)$r['id'])).'</td><td>'.e($r['unit']).'</td></tr>'; echo '</table><h3>Barang Jadi</h3><table><tr><th>Nama</th><th>Stok</th><th>Satuan</th></tr>'; foreach(all('SELECT * FROM finished_products ORDER BY name') as $r) echo '<tr><td>'.e($r['name']).'</td><td>'.dec(stock_qty('finished',(int)$r['id'])).'</td><td>'.e($r['unit']).'</td></tr>'; echo '</table>';
}
elseif($page==='stock_opname'){
 if(!can_stock_opname()){ http_response_code(403); die('Akses ditolak.'); }
 ensure_stock_opname_tables();
 $type=($_GET['type']??'raw')==='finished'?'finished':'raw';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $type=($_POST['item_type']??'raw')==='finished'?'finished':'raw';
  $table=$type==='raw'?'raw_materials':'finished_products';
  $items=[];
  foreach(($_POST['physical_qty']??[]) as $id=>$physicalRaw){
   $physicalRaw=trim((string)$physicalRaw);
   if($physicalRaw==='') continue;
   $id=(int)$id; $physical=(float)$physicalRaw;
   if($id<=0 || $physical<0){ flash('Qty fisik tidak valid.','err'); redirect('?page=stock_opname&type='.$type); }
   $row=one('SELECT id,name,unit FROM '.$table.' WHERE id=? AND is_active=1',[$id]);
   if(!$row) continue;
   $system=stock_qty($type,$id); $diff=round($physical-$system,4);
   if(abs($diff)<0.0001) continue;
   $items[]=['id'=>$id,'name'=>$row['name'],'unit'=>$row['unit'],'system'=>$system,'physical'=>$physical,'diff'=>$diff];
  }
  if(count($items)<1){ flash('Tidak ada selisih stok yang perlu disesuaikan.','ok'); redirect('?page=stock_opname&type='.$type); }
  $no=next_opname_no();
  try{
   db()->beginTransaction();
   execq('INSERT INTO stock_opname_headers(opname_no,opname_date,item_type,total_items,notes,created_by) VALUES(?,?,?,?,?,?)',[$no,postval('opname_date',date('Y-m-d')),$type,count($items),postval('notes'),(int)($u['id']??0)]);
   $oid=(int)db()->lastInsertId();
   foreach($items as $it){
    execq('INSERT INTO stock_opname_items(opname_id,item_type,item_id,item_name,system_qty,physical_qty,difference_qty,unit) VALUES(?,?,?,?,?,?,?,?)',[$oid,$type,$it['id'],$it['name'],$it['system'],$it['physical'],$it['diff'],$it['unit']]);
    if($it['diff']>0) add_ledger($type,$it['id'],'stock_opname','stock_opname_headers',$oid,(float)$it['diff'],0,null,$no,(int)($u['id']??0));
    else add_ledger($type,$it['id'],'stock_opname','stock_opname_headers',$oid,0,abs((float)$it['diff']),null,$no,(int)($u['id']??0));
   }
   db()->commit(); flash('Stok opname disimpan: '.$no.'. Item disesuaikan: '.count($items)); redirect('?page=stock_opname&type='.$type);
  }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); flash('Gagal menyimpan stok opname: '.$e->getMessage(),'err'); redirect('?page=stock_opname&type='.$type); }
 }
 h2('Stok Opname Dapur');
 echo '<div class="actions"><a class="btn '.($type==='raw'?'secondary':'light').'" href="?page=stock_opname&type=raw">Bahan Baku</a><a class="btn '.($type==='finished'?'secondary':'light').'" href="?page=stock_opname&type=finished">Produk Jadi</a></div>';
 echo '<p class="muted">Isi qty fisik hanya pada item yang ingin disesuaikan. Selisih akan dibuat sebagai transaksi <span class="code">stock_opname</span> di stock ledger.</p>';
 $table=$type==='raw'?'raw_materials':'finished_products'; $rows=all('SELECT * FROM '.$table.' WHERE is_active=1 ORDER BY name');
 echo '<form method="post">'.csrf_field().'<input type="hidden" name="item_type" value="'.e($type).'"><div class="form-grid"><p><label>Tanggal opname<input name="opname_date" type="date" value="'.date('Y-m-d').'" required></label></p><p><label>Catatan<input name="notes" placeholder="Misal: penyesuaian setelah test"></label></p></div><div class="table-scroll"><table><tr><th>Item</th><th>Stok Sistem</th><th>Qty Fisik</th><th>Satuan</th></tr>';
 foreach($rows as $r){ $st=stock_qty($type,(int)$r['id']); echo '<tr><td>'.e($r['name']).'</td><td>'.dec($st).'</td><td><input name="physical_qty['.(int)$r['id'].']" type="number" step="0.0001" min="0" placeholder="kosongkan bila tidak diopname"></td><td>'.e($r['unit']).'</td></tr>'; }
 echo '</table></div><p><button class="btn">Simpan Penyesuaian Stok</button></p></form>';
 echo '<h3>Histori Opname</h3><table><tr><th>No</th><th>Tanggal</th><th>Jenis</th><th>Item Disesuaikan</th><th>Catatan</th><th>Detail</th></tr>';
 foreach(all('SELECT h.*,u.name user_name FROM stock_opname_headers h LEFT JOIN users u ON u.id=h.created_by ORDER BY h.id DESC LIMIT 25') as $h){ $details=all('SELECT * FROM stock_opname_items WHERE opname_id=? ORDER BY id',[(int)$h['id']]); $html=''; foreach($details as $d){ $html.='<tr><td>'.e($d['item_name']).'</td><td>'.dec($d['system_qty']).'</td><td>'.dec($d['physical_qty']).'</td><td>'.dec($d['difference_qty']).'</td><td>'.e($d['unit']).'</td></tr>'; } echo '<tr><td>'.e($h['opname_no']).'</td><td>'.e($h['opname_date']).'</td><td>'.e($h['item_type']==='raw'?'Bahan Baku':'Produk Jadi').'</td><td>'.(int)$h['total_items'].'</td><td>'.e($h['notes']).'</td><td><details><summary>Lihat</summary><table class="nested-table"><tr><th>Item</th><th>Sistem</th><th>Fisik</th><th>Selisih</th><th>Unit</th></tr>'.$html.'</table></details></td></tr>'; }
 echo '</table>';
}
elseif($page==='sales'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=$_POST['act']??'create_transfer';
  if($act==='resync_transfer'){
   $sid=(int)($_POST['id']??0);
   $h=$sid>0?one('SELECT * FROM kitchen_sales_headers WHERE id=?',[$sid]):null;
   if(!$h){ flash('Transfer tidak ditemukan.','err'); redirect('?page=sales'); }
   $store=one('SELECT * FROM stores WHERE id=?',[(int)$h['store_id']]);
   if(!$store){ flash('Toko tujuan tidak ditemukan.','err'); redirect('?page=sales'); }
   $payload=build_transfer_payload($store,$sid);
   $res=send_kitchen_transfer($store,$payload);
   $newStatus=$res['ok']?'sent_to_store':'failed_sync';
   $remoteText=$res['curl_error']!==''?$res['curl_error']:($res['body']!==''?$res['body']:$res['message']);
   execq('UPDATE kitchen_sales_headers SET status=?, synced_at=NOW(), remote_response=? WHERE id=?',[$newStatus,$remoteText,$sid]);
   api_log_event((int)$store['id'],$res['endpoint'],'out',$newStatus,$res['message'],['request'=>$payload,'response'=>response_payload((int)$res['http_code'],(string)$res['body'],(string)$res['curl_error'])]);
   flash(($res['ok']?'Kirim ulang berhasil. Transfer menunggu konfirmasi toko.':'Kirim ulang gagal. '.$res['message']).' No: '.$h['sale_no'],$res['ok']?'ok':'err');
   redirect('?page=sales');
  }
  $store=one('SELECT * FROM stores WHERE id=? AND is_active=1',[(int)($_POST['store_id']??0)]);
  if(!$store){ flash('Toko tujuan tidak valid/nonaktif.','err'); redirect('?page=sales'); }
  $rows=[]; $total=0.0;
  foreach($_POST['finished_product_id']??[] as $i=>$fid){
   $fid=(int)$fid; $qty=(float)($_POST['qty'][$i]??0); if($fid<=0||$qty<=0) continue;
   $fp=one('SELECT * FROM finished_products WHERE id=? AND is_active=1',[$fid]);
   if(!$fp){ flash('Produk jadi tidak valid/nonaktif.','err'); redirect('?page=sales'); }
   $stock=stock_qty('finished',$fid);
   if($stock+0.0001<$qty){ flash('Stok barang jadi tidak cukup untuk '.($fp['name']??'produk').'. Stok saat ini: '.dec($stock),'err'); redirect('?page=sales'); }
   $priceRaw=trim((string)($_POST['transfer_price'][$i]??'')); $price=$priceRaw===''?(float)$fp['transfer_price']:(float)$priceRaw;
   $sub=$qty*$price; $total+=$sub;
   $rows[]=['fp'=>$fp,'qty'=>$qty,'price'=>$price,'subtotal'=>$sub];
  }
  if(count($rows)<1){ flash('Minimal 1 produk dan qty wajib diisi.','err'); redirect('?page=sales'); }
  $no=next_no('KDS','kitchen_sales_headers','sale_no');
  try{
   db()->beginTransaction();
   execq('INSERT INTO kitchen_sales_headers(sale_no,sale_date,store_id,sale_type,status,notes,created_by,posted_at) VALUES(?,?,?,?,?,?,?,NOW())',[$no,postval('sale_date',date('Y-m-d')),(int)$store['id'],'store_distribution','posted',postval('notes'),(int)($u['id']??0)]);
   $sid=(int)db()->lastInsertId();
   foreach($rows as $r){ $fp=$r['fp']; execq('INSERT INTO kitchen_sales_items(sale_id,finished_product_id,qty,unit,transfer_price,subtotal) VALUES(?,?,?,?,?,?)',[$sid,(int)$fp['id'],$r['qty'],$fp['unit'],$r['price'],$r['subtotal']]); add_ledger('finished',(int)$fp['id'],'sale_to_store','kitchen_sales_headers',$sid,0,(float)$r['qty'],(float)$r['price'],$no,(int)($u['id']??0)); }
   execq('UPDATE kitchen_sales_headers SET total_amount=? WHERE id=?',[$total,$sid]);
   db()->commit();
  }catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); flash('Gagal membuat transfer: '.$e->getMessage(),'err'); redirect('?page=sales'); }
  $payload=build_transfer_payload($store,$sid);
  $res=send_kitchen_transfer($store,$payload);
  $remoteText=$res['curl_error']!==''?$res['curl_error']:($res['body']!==''?$res['body']:$res['message']);
  $status=$res['ok']?'sent_to_store':'failed_sync';
  $msg=$res['ok']?'Transfer dikirim ke toko dan menunggu konfirmasi penerimaan manager cabang.':'Penjualan/transfer ke toko dibuat tetapi gagal sync. '.$res['message'];
  if($res['remote_status']==='pending_confirmation') $msg='Transfer dikirim ke toko. Status toko: pending konfirmasi manager.';
  if($res['remote_status']==='duplicate') $msg='Transfer sudah pernah diterima di toko. Tidak dibuat ganda.';
  execq('UPDATE kitchen_sales_headers SET status=?, synced_at=NOW(), remote_response=? WHERE id=?',[$status,$remoteText,$sid]);
  api_log_event((int)$store['id'],$res['endpoint'],'out',$status,$res['message'],['request'=>$payload,'response'=>response_payload((int)$res['http_code'],(string)$res['body'],(string)$res['curl_error'])]);
  flash($msg.' No: '.$no, $status==='failed_sync'?'err':'ok'); redirect('?page=sales');
 }
 h2('Penjualan / Distribusi ke Toko'); $fps=all('SELECT * FROM finished_products WHERE is_active=1 ORDER BY name'); echo '<form method="post">'.csrf_field().'<input type="hidden" name="act" value="create_transfer"><div class="form-grid"><p><label>Tanggal<input name="sale_date" type="date" value="'.date('Y-m-d').'"></label></p><p><label>Toko Tujuan<select name="store_id">'; foreach(all('SELECT * FROM stores WHERE is_active=1 ORDER BY store_name') as $s) echo '<option value="'.(int)$s['id'].'">'.e($s['store_name']).'</option>'; echo '</select></label></p><p><label>Catatan<input name="notes"></label></p></div><table><tr><th>Produk</th><th>Qty</th><th>Harga Jual Dapur</th><th>Stok</th></tr>'; for($i=0;$i<6;$i++){ echo '<tr><td><select name="finished_product_id[]"><option value="">-</option>'; foreach($fps as $fp) echo '<option value="'.(int)$fp['id'].'">'.e($fp['name']).'</option>'; echo '</select></td><td><input name="qty[]" type="number" step="0.0001"></td><td><input name="transfer_price[]" type="number" step="0.01" placeholder="otomatis dari produk"></td><td class="muted">isi sesuai stok</td></tr>'; } echo '</table><p><button class="btn">Posting & Kirim API ke Toko</button></p></form>'; echo '<h3>Riwayat Transfer</h3><table><tr><th>No</th><th>Toko</th><th>Total</th><th>Status</th><th>Detail API</th><th>Aksi</th></tr>'; foreach(all('SELECT h.*,s.store_name FROM kitchen_sales_headers h LEFT JOIN stores s ON s.id=h.store_id ORDER BY h.id DESC LIMIT 30') as $r){ $detail=trim((string)($r['remote_response']??'')); echo '<tr><td>'.e($r['sale_no']).'</td><td>'.e($r['store_name']).'</td><td>'.rupiah($r['total_amount']).'</td><td><span class="badge">'.e($r['status']).'</span></td><td>'.($detail!==''?'<details><summary>Lihat</summary><pre class="log-pre">'.e(short_text($detail,1200)).'</pre></details>':'-').'</td><td>'.($r['status']==='failed_sync'?'<form method="post">'.csrf_field().'<input type="hidden" name="act" value="resync_transfer"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light">Kirim Ulang</button></form>':'-').'</td></tr>'; } echo '</table>';
}
elseif($page==='activity_types'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=$_POST['act']??'';
  $name=postval('activity_name');
  $category=postval('category');
  $unit=postval('unit_name','kegiatan');
  $weight=(float)postval('point_weight','1');
  $active=((string)($_POST['is_active']??'1')==='1')?1:0;
  if($name===''){ flash('Nama kegiatan wajib diisi.','err'); redirect('?page=activity_types'); }
  if($weight<0){ flash('Bobot poin tidak boleh minus.','err'); redirect('?page=activity_types'); }
  if($act==='activity_update'){
   $id=(int)($_POST['id']??0);
   $row=one('SELECT * FROM activity_types WHERE id=?',[$id]);
   if(!$row){ flash('Kegiatan tidak ditemukan.','err'); redirect('?page=activity_types'); }
   execq('UPDATE activity_types SET activity_name=?, category=?, unit_name=?, point_weight=?, is_active=? WHERE id=?',[$name,$category,$unit,$weight,$active,$id]);
   flash('Daftar kegiatan pegawai diperbarui.'); redirect('?page=activity_types');
  }
  execq('INSERT INTO activity_types(activity_name,category,unit_name,point_weight,is_active) VALUES(?,?,?,?,?)',[$name,$category,$unit,$weight,$active]);
  flash('Kegiatan pegawai baru disimpan.'); redirect('?page=activity_types');
 }
 h2('Daftar Kegiatan Pegawai');
 echo '<p class="muted">Kelola jenis kegiatan dan bobot poin. Perubahan bobot hanya berlaku untuk input berikutnya; riwayat kegiatan lama tetap memakai poin yang sudah tercatat.</p>';
 $editId=(int)($_GET['edit']??0); $edit=$editId>0?one('SELECT * FROM activity_types WHERE id=?',[$editId]):null;
 echo '<h3>'.($edit?'Edit Kegiatan':'Tambah Kegiatan').'</h3><form method="post" class="form-grid compact-form">'.csrf_field().'<input type="hidden" name="act" value="'.($edit?'activity_update':'activity_create').'">';
 if($edit) echo '<input type="hidden" name="id" value="'.(int)$edit['id'].'">';
 echo '<p><label>Nama Kegiatan<input name="activity_name" value="'.e($edit['activity_name']??'').'" required></label></p><p><label>Kategori<input name="category" value="'.e($edit['category']??'').'"></label></p><p><label>Satuan<input name="unit_name" value="'.e($edit['unit_name']??'kegiatan').'"></label></p><p><label>Bobot Poin<input name="point_weight" type="number" step="0.01" min="0" value="'.e($edit['point_weight']??'1').'" required></label></p><p><label>Status<select name="is_active"><option value="1" '.((!$edit || (int)$edit['is_active']===1)?'selected':'').'>Aktif</option><option value="0" '.(($edit && (int)$edit['is_active']===0)?'selected':'').'>Nonaktif</option></select></label></p><p><button class="btn">'.($edit?'Update':'Simpan').'</button> '.($edit?'<a class="btn light" href="?page=activity_types">Batal</a>':'').'</p></form>';
 echo '<h3>Daftar Kegiatan</h3><table><tr><th>Nama Kegiatan</th><th>Kategori</th><th>Satuan</th><th>Bobot</th><th>Status</th><th>Aksi</th></tr>';
 foreach(all('SELECT * FROM activity_types ORDER BY is_active DESC, activity_name') as $r){
  echo '<tr><td>'.e($r['activity_name']).'</td><td>'.e($r['category']).'</td><td>'.e($r['unit_name']).'</td><td>'.dec($r['point_weight']).'</td><td>'.((int)$r['is_active']===1?'Aktif':'Nonaktif').'</td><td><a class="btn light" href="?page=activity_types&edit='.(int)$r['id'].'">Edit</a></td></tr>';
 }
 echo '</table>';
}
elseif($page==='activities'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['act']??'')==='emp'){execq('INSERT INTO employees(employee_name,phone,is_active) VALUES(?,?,1)',[postval('employee_name'),postval('phone')]); flash('Pegawai disimpan.'); redirect('?page=activities');}
  if(($_POST['act']??'')==='type'){execq('INSERT INTO activity_types(activity_name,category,unit_name,point_weight,is_active) VALUES(?,?,?,?,1)',[postval('activity_name'),postval('category'),postval('unit_name','kegiatan'),(float)postval('point_weight','1')]); flash('Jenis kegiatan disimpan.'); redirect('?page=activity_types');}
  $at=one('SELECT * FROM activity_types WHERE id=? AND is_active=1',[(int)$_POST['activity_type_id']]);
  if(!$at){ flash('Jenis kegiatan tidak valid atau nonaktif.','err'); redirect('?page=activities'); }
  $qty=(float)postval('qty','1'); $w=(float)$at['point_weight'];
  execq('INSERT INTO employee_activities(activity_date,employee_id,activity_type_id,qty,point_weight,total_points,notes,created_by) VALUES(?,?,?,?,?,?,?,?)',[postval('activity_date',date('Y-m-d')),(int)$_POST['employee_id'],(int)$at['id'],$qty,$w,$qty*$w,postval('notes'),(int)($u['id']??0)]);
  flash('Kegiatan pegawai dicatat.'); redirect('?page=activities');
 }
 h2('Kegiatan Pegawai & Bobot Poin');
 echo '<h3>Tambah Pegawai</h3><form method="post" class="actions">'.csrf_field().'<input type="hidden" name="act" value="emp"><input name="employee_name" placeholder="Nama pegawai" required><input name="phone" placeholder="HP"><button class="btn light">Simpan Pegawai</button></form>';
 echo '<div class="actions"><a class="btn light" href="?page=activity_types">Daftar / Edit Kegiatan Pegawai</a></div>';
 echo '<h3>Input Kegiatan</h3><form method="post" class="form-grid">'.csrf_field().'<p><label>Tanggal<input name="activity_date" type="date" value="'.date('Y-m-d').'"></label></p><p><label>Pegawai<select name="employee_id">'; foreach(all('SELECT * FROM employees WHERE is_active=1 ORDER BY employee_name') as $emp) echo '<option value="'.(int)$emp['id'].'">'.e($emp['employee_name']).'</option>'; echo '</select></label></p><p><label>Kegiatan<select name="activity_type_id">'; foreach(all('SELECT * FROM activity_types WHERE is_active=1 ORDER BY activity_name') as $a) echo '<option value="'.(int)$a['id'].'">'.e($a['activity_name'].' ('.$a['point_weight'].')').'</option>'; echo '</select></label></p><p><label>Qty<input name="qty" type="number" step="0.0001" value="1"></label></p><p><label>Catatan<input name="notes"></label></p><p><button class="btn">Catat</button></p></form>';
 echo '<table><tr><th>Tanggal</th><th>Pegawai</th><th>Kegiatan</th><th>Poin</th></tr>'; foreach(all('SELECT ea.*,e.employee_name,a.activity_name FROM employee_activities ea JOIN employees e ON e.id=ea.employee_id JOIN activity_types a ON a.id=ea.activity_type_id ORDER BY ea.id DESC LIMIT 40') as $r) echo '<tr><td>'.e($r['activity_date']).'</td><td>'.e($r['employee_name']).'</td><td>'.e($r['activity_name']).'</td><td>'.dec($r['total_points']).'</td></tr>'; echo '</table>';
}
elseif($page==='remuneration'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $name=postval('period_name');$start=postval('start_date');$end=postval('end_date');$fund=(float)postval('total_fund','0'); execq('INSERT INTO remuneration_periods(period_name,start_date,end_date,total_fund,status) VALUES(?,?,?,?,?)',[$name,$start,$end,$fund,'draft']); $pid=(int)db()->lastInsertId(); $rows=all('SELECT employee_id,SUM(total_points) pts FROM employee_activities WHERE activity_date BETWEEN ? AND ? GROUP BY employee_id',[$start,$end]); $total=array_sum(array_map(fn($r)=>(float)$r['pts'],$rows)); foreach($rows as $r){$pct=$total>0?(float)$r['pts']/$total:0; execq('INSERT INTO remuneration_items(period_id,employee_id,total_points,point_percent,amount) VALUES(?,?,?,?,?)',[$pid,(int)$r['employee_id'],(float)$r['pts'],$pct,$pct*$fund]);} flash('Perhitungan remunerasi dibuat.'); redirect('?page=remuneration'); }
 h2('Remunerasi'); echo '<form method="post" class="form-grid">'.csrf_field().'<p><label>Nama Periode<input name="period_name" value="Remun '.date('M Y').'"></label></p><p><label>Awal<input name="start_date" type="date" required></label></p><p><label>Akhir<input name="end_date" type="date" required></label></p><p><label>Total Dana<input name="total_fund" type="number"></label></p><p><button class="btn">Hitung</button></p></form>'; foreach(all('SELECT * FROM remuneration_periods ORDER BY id DESC LIMIT 10') as $p){echo '<h3>'.e($p['period_name']).' <span class="muted">'.e($p['start_date'].' s.d. '.$p['end_date']).'</span></h3><table><tr><th>Pegawai</th><th>Poin</th><th>%</th><th>Nominal</th></tr>'; foreach(all('SELECT ri.*,e.employee_name FROM remuneration_items ri JOIN employees e ON e.id=ri.employee_id WHERE period_id=? ORDER BY amount DESC',[(int)$p['id']]) as $r) echo '<tr><td>'.e($r['employee_name']).'</td><td>'.dec($r['total_points']).'</td><td>'.number_format((float)$r['point_percent']*100,2).'%</td><td>'.rupiah($r['amount']).'</td></tr>'; echo '</table>'; }
}
elseif($page==='users'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $hash=password_hash(postval('password'),PASSWORD_DEFAULT); execq('INSERT INTO users(username,name,email,password_hash,role_id,is_active) VALUES(?,?,?,?,?,1)',[postval('username'),postval('name'),postval('email'),$hash,(int)$_POST['role_id']]); flash('User dibuat.'); redirect('?page=users'); }
 h2('User & Role'); echo '<form method="post" class="form-grid">'.csrf_field().'<p><label>Username<input name="username" required></label></p><p><label>Nama<input name="name" required></label></p><p><label>Email<input name="email"></label></p><p><label>Password<input name="password" type="password" required></label></p><p><label>Role<select name="role_id">'; foreach(all('SELECT * FROM roles ORDER BY id') as $r) echo '<option value="'.(int)$r['id'].'">'.e($r['role_name']).'</option>'; echo '</select></label></p><p><button class="btn">Buat User</button></p></form><table><tr><th>Username</th><th>Nama</th><th>Role</th></tr>'; foreach(all('SELECT u.*,r.role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id') as $r) echo '<tr><td>'.e($r['username']).'</td><td>'.e($r['name']).'</td><td>'.e($r['role_name']).'</td></tr>'; echo '</table>';
}

elseif($page==='error_log'){
 if(!is_owner()){ http_response_code(403); die('Akses ditolak.'); }
 h2('Error Log API');
 echo '<p class="muted">Log ini khusus owner. Error dari test API, import produk, transfer stok, dan sync gagal akan masuk ke sini.</p>';
 if(!table_exists('api_logs')){ echo '<div class="notice err">Tabel api_logs belum ada.</div>'; }
 else{
  $scope=$_GET['scope']??'error'; $storeFilter=(int)($_GET['store_id']??0); $endpointFilter=trim((string)($_GET['endpoint']??''));
  echo '<form method="get" class="form-grid compact-form"><input type="hidden" name="page" value="error_log"><p><label>Tampilan<select name="scope"><option value="error" '.($scope==='error'?'selected':'').'>Error saja</option><option value="all" '.($scope==='all'?'selected':'').'>Semua log</option></select></label></p><p><label>Toko<select name="store_id"><option value="0">Semua toko</option>'; foreach(all('SELECT * FROM stores ORDER BY store_name') as $st) echo '<option value="'.(int)$st['id'].'" '.($storeFilter===(int)$st['id']?'selected':'').'>'.e($st['store_name']).'</option>'; echo '</select></label></p><p><label>Endpoint mengandung<input name="endpoint" value="'.e($endpointFilter).'"></label></p><p><button class="btn light">Filter</button></p></form>';
  $where=[]; $params=[];
  if($scope!=='all') $where[]="(LOWER(al.status) LIKE '%fail%' OR LOWER(al.status) LIKE '%error%' OR LOWER(al.status) LIKE '%invalid%' OR LOWER(al.status) IN ('failed_sync','import_failed','transfer_test_failed','product_test_failed','ping_failed'))";
  if($storeFilter>0){ $where[]='al.store_id=?'; $params[]=$storeFilter; }
  if($endpointFilter!==''){ $where[]='al.endpoint LIKE ?'; $params[]='%'.$endpointFilter.'%'; }
  $sql='SELECT al.*,s.store_name FROM api_logs al LEFT JOIN stores s ON s.id=al.store_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY al.id DESC LIMIT 200';
  echo '<table><tr><th>Waktu</th><th>Toko</th><th>Endpoint</th><th>Status</th><th>Pesan</th><th>Payload/Response</th></tr>';
  foreach(all($sql,$params) as $r){ $payload=trim((string)($r['payload_json']??'')); echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['store_name']??'-').'</td><td><span class="code">'.e($r['endpoint']).'</span></td><td><span class="badge">'.e($r['status']).'</span></td><td>'.e($r['message']).'</td><td>'.($payload!==''?'<details><summary>Lihat</summary><pre class="log-pre">'.e(short_text($payload,1800)).'</pre></details>':'-').'</td></tr>'; }
  echo '</table>';
 }
 echo '<h3>Transfer Stok Gagal Sync</h3><table><tr><th>No</th><th>Tanggal</th><th>Toko</th><th>Total</th><th>Response</th></tr>';
 foreach(all("SELECT h.*,s.store_name FROM kitchen_sales_headers h LEFT JOIN stores s ON s.id=h.store_id WHERE h.status='failed_sync' ORDER BY h.id DESC LIMIT 50") as $r){ $detail=trim((string)($r['remote_response']??'')); echo '<tr><td>'.e($r['sale_no']).'</td><td>'.e($r['sale_date']).'</td><td>'.e($r['store_name']).'</td><td>'.rupiah($r['total_amount']).'</td><td>'.($detail!==''?'<details><summary>Lihat</summary><pre class="log-pre">'.e(short_text($detail,1800)).'</pre></details>':'-').'</td></tr>'; }
 echo '</table>';
}

elseif($page==='owner_permissions'){
 if(!is_owner()){ http_response_code(403); die('Akses ditolak.'); }
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $roleId=(int)($_POST['role_id']??0);
  $role=$roleId>0?one('SELECT * FROM roles WHERE id=?',[$roleId]):null;
  if(!$role || in_array((string)$role['role_key'],['owner','superadmin'],true)){ flash('Role owner tidak perlu diatur permission-nya.','err'); redirect('?page=owner_permissions'); }
  execq('DELETE FROM role_permissions WHERE role_id=?',[$roleId]);
  foreach(($_POST['permission_id']??[]) as $pid){ $pid=(int)$pid; if($pid>0) execq('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)',[$roleId,$pid]); }
  flash('Permission role '.($role['role_name']??'').' diperbarui.'); redirect('?page=owner_permissions&role_id='.$roleId);
 }
 h2('Pengaturan User Permission');
 $roles=all("SELECT * FROM roles WHERE role_key NOT IN ('owner','superadmin') ORDER BY id");
 $roleId=(int)($_GET['role_id']??($roles[0]['id']??0));
 $selectedRole=$roleId>0?one('SELECT * FROM roles WHERE id=?',[$roleId]):null;
 if(!$selectedRole || in_array((string)$selectedRole['role_key'],['owner','superadmin'],true)){ $selectedRole=$roles[0]??null; $roleId=(int)($selectedRole['id']??0); }
 echo '<p class="muted">Owner selalu memiliki akses penuh. Permission di sini berlaku untuk role selain owner.</p>';
 echo '<form method="get" class="actions"><input type="hidden" name="page" value="owner_permissions"><label>Role <select name="role_id" onchange="this.form.submit()">'; foreach($roles as $r) echo '<option value="'.(int)$r['id'].'" '.((int)$r['id']===$roleId?'selected':'').'>'.e($r['role_name']).'</option>'; echo '</select></label><noscript><button class="btn light">Pilih</button></noscript></form>';
 if($selectedRole){
  $active=[]; foreach(all('SELECT permission_id FROM role_permissions WHERE role_id=?',[$roleId]) as $rp) $active[(int)$rp['permission_id']]=true;
  echo '<form method="post">'.csrf_field().'<input type="hidden" name="role_id" value="'.$roleId.'"><table><tr><th>Aktif</th><th>Permission</th><th>Keterangan</th></tr>';
  foreach(all('SELECT * FROM permissions ORDER BY id') as $p){ echo '<tr><td><input type="checkbox" name="permission_id[]" value="'.(int)$p['id'].'" '.(isset($active[(int)$p['id']])?'checked':'').'></td><td>'.e($p['permission_key']).'</td><td>'.e($p['permission_name']).'</td></tr>'; }
  echo '</table><p><button class="btn">Simpan Permission</button></p></form>';
 }
}
elseif($page==='api'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $plain='kd_'.bin2hex(random_bytes(24)); execq('INSERT INTO api_tokens(token_name,token_hash,permissions_json,is_active) VALUES(?,?,?,1)',[postval('token_name'),hash('sha256',$plain),json_encode(['products.view','stock.view','*'])]); flash('Token dibuat. Simpan token ini sekarang: '.$plain); redirect('?page=api'); }
 h2('API Token Dapur'); echo '<p class="muted">Token ini untuk integrasi eksternal bila diperlukan. Token toko untuk menerima kiriman dapur diatur di menu Toko & API.</p><form method="post" class="actions">'.csrf_field().'<input name="token_name" placeholder="Nama token" required><button class="btn">Generate Token</button></form><table><tr><th>Nama</th><th>Status</th><th>Terakhir Dipakai</th></tr>'; foreach(all('SELECT * FROM api_tokens ORDER BY id DESC') as $r) echo '<tr><td>'.e($r['token_name']).'</td><td>'.($r['is_active']?'Aktif':'Nonaktif').'</td><td>'.e($r['last_used_at']).'</td></tr>'; echo '</table>';
}
?></div></main></div></body></html>
