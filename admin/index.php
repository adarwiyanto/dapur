<?php
require_once __DIR__.'/../core/auth.php'; require_once __DIR__.'/../core/api_pairing.php'; require_login(); verify_csrf();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
ob_start(); // patch: keep AJAX JSON clean even when admin shell is buffered
$u=current_user(); $page=$_GET['page']??'dashboard';
$menus=[
 'dashboard'=>['Dashboard','🏠','dashboard'], 'stores'=>['Toko & API','🔌','stores'], 'finished'=>['Produk Jadi','📦','products'], 'finished_hidden'=>['Hide Produk','↳','products'], 'raw'=>['Bahan Baku','🥣','raw_materials'], 'purchases'=>['Pembelian Bahan Baku','🛒','purchases'], 'expenses'=>['Pengeluaran','💸','purchases'], 'payment_requests'=>['Permintaan Pembayaran','🧾','purchases'], 'expense_categories'=>['Setting Pengeluaran','↳','purchases'], 'bom'=>['BOM','🧾','bom'], 'bom_hidden'=>['Hide BOM','↳','bom'], 'production'=>['Produksi','🏭','production'], 'stock'=>['Stok','📊','stock'], 'stock_opname'=>['Stok Opname','🧮','stock_opname'], 'sales'=>['Penjualan ke Toko','🚚','sales_distribution'], 'activities'=>['Kegiatan Pegawai','⭐','activities'], 'activity_types'=>['Daftar Kegiatan Pegawai','↳','activities'], 'remuneration'=>['Remunerasi','💰','remuneration'], 'users'=>['User & Role','👤','users'], 'hope_connection'=>['Koneksi ke HOPe','🔗','api'], 'api_integrations'=>['API & Integrasi','🔌','api'], 'company_settings'=>['Edit Perusahaan','🏢','users'], 'error_log'=>['Error Log','🧯','error_log','owner'], 'owner_permissions'=>['Pengaturan Permission','🛡️','permissions','owner'], 'api'=>['API Token','🔐','api']
];
if(!isset($menus[$page])) $page='dashboard'; require_perm($menus[$page][2]); if(($menus[$page][3]??'')==='owner' && !is_owner()){ http_response_code(403); die('Akses ditolak.'); }
function h2($t){echo '<h2>'.e($t).'</h2>';}
function next_no($prefix,$table,$field){return $prefix.'-'.date('Ymd').'-'.str_pad((string)(((int)(db()->query("SELECT COUNT(*) FROM $table")->fetchColumn()))+1),4,'0',STR_PAD_LEFT);} 
function postval($k,$d=''){return trim((string)($_POST[$k]??$d));}
function company_logo_url(): string {
 $custom=trim((string)setting('company_logo',''));
 if($custom!=='' && preg_match('/^[A-Za-z0-9._-]+$/',$custom) && is_file(__DIR__.'/../storage/'.$custom)) return '../storage/'.rawurlencode($custom);
 return '../assets/adena-default.jpg';
}
function company_info(): array {
 return [
  'name'=>(string)setting('company_name','Dapur Adena'),
  'branch'=>(string)setting('company_branch',''),
  'address'=>(string)setting('company_address',''),
  'phone'=>(string)setting('company_phone',''),
  'email'=>(string)setting('company_email',''),
  'extra'=>(string)setting('company_extra',''),
 ];
}
function dapur_role_label(string $roleKey): string {
 $roleKey=strtolower(trim($roleKey));
 return match($roleKey){
  'owner'=>'Owner',
  'admin_dapur'=>'Admin Dapur',
  'manager_dapur','kepala_dapur'=>'Manajer Dapur',
  default=>'Pegawai Dapur',
 };
}
function ensure_dapur_employee_role_column(): void {
 try{ if(table_exists('employees') && !column_exists_local('employees','role_key')) db()->exec("ALTER TABLE employees ADD COLUMN role_key VARCHAR(50) NOT NULL DEFAULT 'pegawai_dapur' AFTER phone"); }catch(Throwable $e){}
}
function normalize_dapur_roles_runtime(): void {
 try{
  execq("INSERT IGNORE INTO roles(role_key,role_name) VALUES ('owner','Owner'),('admin_dapur','Admin Dapur'),('manager_dapur','Manajer Dapur'),('pegawai_dapur','Pegawai Dapur')");
  execq("UPDATE roles SET role_name='Owner' WHERE role_key='owner'");
  execq("UPDATE roles SET role_name='Admin Dapur' WHERE role_key='admin_dapur'");
  execq("UPDATE roles SET role_name='Manajer Dapur' WHERE role_key IN ('manager_dapur','kepala_dapur')");
  execq("UPDATE roles SET role_name='Pegawai Dapur' WHERE role_key IN ('pegawai_dapur','kasir_dapur','viewer')");
 }catch(Throwable $e){}
}

function column_exists_local(string $table,string $column): bool { try{ $st=db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$column]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }catch(Throwable $e){ return false; } }
function ensure_pairing_notification_columns(): void {
 try{ ensure_api_pairing_schema(); }catch(Throwable $e){}
 if(table_exists('api_pairing_requests')){
  try{ if(!column_exists_local('api_pairing_requests','notification_dismissed_at')) db()->exec("ALTER TABLE api_pairing_requests ADD COLUMN notification_dismissed_at DATETIME NULL"); }catch(Throwable $e){}
  try{ if(!column_exists_local('api_pairing_requests','notification_dismissed_by')) db()->exec("ALTER TABLE api_pairing_requests ADD COLUMN notification_dismissed_by BIGINT NULL"); }catch(Throwable $e){}
 }
}
function status_badge2($s){ $c=$s==='approved'||$s==='active'||$s==='ok'?'ok':($s==='pending'?'warn':'danger'); return '<span class="badge '.$c.'">'.e((string)$s).'</span>'; }

function api_ui_type_key(array $c): string {
 $raw=strtolower(trim((string)(($c['remote_system_type']??'').' '.($c['connection_type']??'').' '.($c['connection_name']??''))));
 if(preg_match('/\b(hope|hp|hope_pos|pos)\b/',$raw) || str_contains($raw,'hope')) return 'hope';
 if(str_contains($raw,'backoffice') || str_contains($raw,'back office') || preg_match('/\bbo\b/',$raw)) return 'backoffice';
 if(str_contains($raw,'adena_store') || str_contains($raw,'store') || str_contains($raw,'toko') || str_contains($raw,'cabang')) return 'store';
 if(str_contains($raw,'dapur')) return 'dapur';
 return trim((string)($c['remote_system_type']??'')) ?: 'external';
}
function api_ui_type_label(array $c): string {
 return match(api_ui_type_key($c)){
  'hope'=>'HOPe/HP',
  'backoffice'=>'Back Office',
  'store'=>'Toko/Cabang',
  'dapur'=>'Dapur',
  default=>e((string)($c['remote_system_type']??'External')),
 };
}
function api_ui_active_status_sql(string $column='status'): string { return "LOWER(TRIM(COALESCE($column,''))) NOT IN ('revoked','deleted','cancelled','canceled','rejected','inactive')"; }
function api_action_form(int $id,string $act,string $label,string $returnPage='api_integrations',string $class='btn light',string $confirm=''): string {
 $ons=$confirm!==''?' onsubmit="return confirm(&quot;'.e($confirm).'&quot;)"':'';
 return '<form method="post" action="api_pairing_action.php"'.$ons.'>'.csrf_field().'<input type="hidden" name="return_page" value="'.e($returnPage).'"><input type="hidden" name="act" value="'.e($act).'"><input type="hidden" name="id" value="'.$id.'"><button class="'.e($class).'">'.e($label).'</button></form>';
}
function api_connection_test_actions(array $c,string $returnPage='api_integrations'): string {
 $cid=(int)($c['id']??0); if($cid<=0) return '';
 $type=api_ui_type_key($c);
 $html='<div class="actions mini">';
 $html.=api_action_form($cid,'test_connection','Test Ping',$returnPage);
 if($type==='hope'){
  $html.=api_action_form($cid,'test_hope_products','Test Produk',$returnPage);
  $html.=api_action_form($cid,'test_hope_transfer','Test Transfer Stok',$returnPage);
  $html.=api_action_form($cid,'refresh_scope','Refresh Scope',$returnPage);
 } elseif($type==='backoffice'){
  $html.=api_action_form($cid,'test_backoffice_health','Test Health',$returnPage);
  $html.=api_action_form($cid,'test_backoffice_dashboard','Test Dashboard',$returnPage);
  $html.=api_action_form($cid,'test_backoffice_kpi','Test KPI Dapur',$returnPage);
  $html.=api_action_form($cid,'test_backoffice_employees','Test Employees',$returnPage);
  $html.=api_action_form($cid,'refresh_scope','Refresh Scope',$returnPage);
 } else {
  $html.=api_action_form($cid,'refresh_scope','Refresh Scope',$returnPage);
 }
 $html.=api_action_form($cid,'revoke_connection','Hapus/Revoke',$returnPage,'btn danger','Cabut koneksi ini?');
 return $html.'</div>';
}

function pairing_pending_rows(int $limit=6): array { ensure_pairing_notification_columns(); try{return all("SELECT * FROM api_pairing_requests WHERE direction='incoming' AND status='pending' AND notification_dismissed_at IS NULL ORDER BY id DESC LIMIT ".$limit);}catch(Throwable $e){return [];} }
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
 if(isset($json['items'])&&is_array($json['items'])) $items=$json['items'];
 elseif(isset($json['products'])&&is_array($json['products'])) $items=$json['products'];
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
function build_transfer_payload(array $store,int $saleId): array {
 ensure_hope_transfer_schema();
 $h=one('SELECT * FROM kitchen_sales_headers WHERE id=?',[$saleId]);
 if(!$h) throw new RuntimeException('Transfer tidak ditemukan.');
 $rows=all('SELECT ksi.*,fp.name AS fp_name,fp.sku,fp.unit AS fp_unit,fpsm.store_product_id,fpsm.store_sku,fpsm.store_product_name FROM kitchen_sales_items ksi LEFT JOIN finished_products fp ON fp.id=ksi.finished_product_id LEFT JOIN finished_product_store_mappings fpsm ON fpsm.finished_product_id=fp.id AND fpsm.store_id=? WHERE ksi.sale_id=? ORDER BY ksi.id',[(int)$store['id'],$saleId]);
 $items=[];
 foreach($rows as $r){
  $type=(string)($r['item_type']??'finished');
  $ref=(int)($r['item_ref_id']??$r['finished_product_id']??0);
  $name=(string)($r['item_name']??''); if($name==='') $name=(string)($r['store_product_name']??$r['fp_name']??'');
  $unit=(string)($r['unit']??''); if($unit==='') $unit=(string)($r['fp_unit']??'pcs');
  $sku=(string)($r['store_sku']??$r['sku']??''); if($sku==='') $sku=($type==='raw'?'DAPUR-RM-':'DAPUR-FP-').$ref;
  $items[]=['store_product_id'=>(string)($r['store_product_id']??$ref),'sku'=>$sku,'name'=>$name,'item_type'=>$type==='raw'?'raw_material':'finished_good','qty'=>(float)$r['qty'],'unit'=>$unit,'transfer_price'=>(float)$r['transfer_price']];
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

function ensure_hope_transfer_schema(): void {
 try{ if(!column_exists_local('kitchen_sales_items','item_type')) db()->exec("ALTER TABLE kitchen_sales_items ADD COLUMN item_type VARCHAR(20) NOT NULL DEFAULT 'finished' AFTER sale_id"); }catch(Throwable $e){}
 try{ if(!column_exists_local('kitchen_sales_items','item_ref_id')) db()->exec("ALTER TABLE kitchen_sales_items ADD COLUMN item_ref_id INT NULL AFTER item_type"); }catch(Throwable $e){}
 try{ if(!column_exists_local('kitchen_sales_items','item_name')) db()->exec("ALTER TABLE kitchen_sales_items ADD COLUMN item_name VARCHAR(180) NULL AFTER item_ref_id"); }catch(Throwable $e){}
 try{ if(!column_exists_local('api_connections','token_plain')) db()->exec("ALTER TABLE api_connections ADD COLUMN token_plain TEXT NULL AFTER token_hash"); }catch(Throwable $e){}
 try{ if(!column_exists_local('api_connections','access_token_plain')) db()->exec("ALTER TABLE api_connections ADD COLUMN access_token_plain TEXT NULL AFTER token_plain"); }catch(Throwable $e){}
}
function dapur_transfer_catalog(): array {
 ensure_hope_transfer_schema();
 $out=[];
 foreach(all('SELECT id,name,unit,transfer_price FROM finished_products WHERE is_active=1 ORDER BY name') as $fp){ $out[]=['key'=>'finished:'.(int)$fp['id'],'type'=>'finished','id'=>(int)$fp['id'],'name'=>$fp['name'],'unit'=>$fp['unit']?:'pcs','price'=>(float)$fp['transfer_price'],'stock'=>stock_qty('finished',(int)$fp['id'])]; }
 foreach(all('SELECT id,name,unit,last_cost FROM raw_materials WHERE is_active=1 ORDER BY name') as $rm){ $out[]=['key'=>'raw:'.(int)$rm['id'],'type'=>'raw','id'=>(int)$rm['id'],'name'=>$rm['name'],'unit'=>$rm['unit']?:'pcs','price'=>(float)$rm['last_cost'],'stock'=>stock_qty('raw',(int)$rm['id'])]; }
 return $out;
}
function dapur_item_by_key(string $key): ?array {
 [$type,$id]=array_pad(explode(':',$key,2),2,'0'); $id=(int)$id; if($id<=0) return null;
 if($type==='raw'){ $r=one('SELECT id,name,unit,last_cost FROM raw_materials WHERE id=? AND is_active=1',[$id]); if(!$r) return null; return ['type'=>'raw','id'=>$id,'name'=>$r['name'],'unit'=>$r['unit']?:'pcs','price'=>(float)$r['last_cost']]; }
 $r=one('SELECT id,name,unit,transfer_price FROM finished_products WHERE id=? AND is_active=1',[$id]); if(!$r) return null; return ['type'=>'finished','id'=>$id,'name'=>$r['name'],'unit'=>$r['unit']?:'pcs','price'=>(float)$r['transfer_price']];
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
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act']??'')==='dismiss_pairing_notification'){ ensure_pairing_notification_columns(); $id=(int)($_POST['id']??0); if($id>0) execq("UPDATE api_pairing_requests SET notification_dismissed_at=NOW(), notification_dismissed_by=? WHERE id=? AND direction='incoming'",[ (int)($u['id']??0), $id]); flash('Notifikasi pairing dihapus dari bell.'); redirect('?page=api_integrations'); }
$f=flash();
normalize_dapur_roles_runtime();
ensure_dapur_employee_role_column();
ensure_hope_transfer_schema();
$pairNotif=pairing_pending_rows(8); $pairNotifCount=count($pairNotif);
?><!doctype html><html><head><meta charset="utf-8"><title>Dapur Adena</title><link rel="stylesheet" href="../assets/app.css?v=20260711b"><script src="../assets/app.js?v=20260711b" defer></script></head><body><div class="app-shell"><aside class="sidebar"><div class="brand">Dapur Adena</div><div class="brand-sub">Produksi • BOM • Multi Toko</div><nav class="nav"><?php
$navGroups=[
 ['label'=>'Utama','items'=>['dashboard','raw','production','stock','stock_opname','sales','remuneration']],
 ['label'=>'Keuangan','items'=>['purchases','expenses','payment_requests','expense_categories']],
 ['label'=>'Produk Jadi','items'=>['finished','finished_hidden']],
 ['label'=>'BOM','items'=>['bom','bom_hidden']],
 ['label'=>'Kegiatan Pegawai','items'=>['activities','activity_types']],
 ['label'=>'Admin','items'=>['users','company_settings','hope_connection','api_integrations','error_log','owner_permissions']],
];
foreach($navGroups as $grp){
 $visible=[]; foreach($grp['items'] as $k){ if(!isset($menus[$k])) continue; $m=$menus[$k]; if(($m[3]??'')==='owner' && !is_owner()) continue; if(can($m[2])) $visible[]=$k; }
 if(!$visible) continue;
 $active=in_array($page,$visible,true); $main=$visible[0];
 if(count($visible)===1){ $m=$menus[$main]; echo '<a class="'.($page===$main?'active':'').'" href="?page='.e($main).'"><span>'.e($m[1]).'</span> '.e($m[0]).'</a>'; continue; }
 echo '<details class="nav-group" '.($active?'open':'').'><summary><span>▸</span> '.e($grp['label']).'</summary>';
 foreach($visible as $k){ $m=$menus[$k]; echo '<a class="sub '.($page===$k?'active':'').'" href="?page='.e($k).'"><span>'.e($m[1]).'</span> '.e($m[0]).'</a>'; }
 echo '</details>';
}
?><a href="../logout.php">⎋ Logout</a></nav></aside><main class="main"><div class="topbar"><div><strong><?=e($menus[$page][0])?></strong><div class="muted small">Login: <?=e($u['name']??'')?></div></div><div class="top-actions"><details class="notify"><summary>🔔<?php if($pairNotifCount>0): ?><span class="notif-badge"><?=e($pairNotifCount)?></span><?php endif; ?></summary><div class="notify-panel"><h4>Request Pairing API</h4><?php if($pairNotif): foreach($pairNotif as $pn): ?><div class="notify-item"><b><?=e($pn['requester_name']??'Peminta')?></b><br><small><?=e(($pn['requester_type']??'-').' • '.($pn['created_at']??''))?></small><div class="actions mini"><a class="btn light" href="?page=api_integrations">Lihat</a><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="act" value="dismiss_pairing_notification"><input type="hidden" name="id" value="<?=(int)$pn['id']?>"><button class="btn light" type="submit">Hapus</button></form></div></div><?php endforeach; else: ?><div class="muted small">Tidak ada request pairing baru.</div><?php endif; ?></div></details></div></div><?php if($f): ?><div class="notice <?=e($f[1])?>"><?=e($f[0])?></div><?php endif; ?><div class="card">
<?php
if($page==='dashboard'){
 h2('Dashboard Dapur'); $stats=[['Bahan baku',db()->query('SELECT COUNT(*) FROM raw_materials')->fetchColumn()],['Produk jadi',db()->query('SELECT COUNT(*) FROM finished_products')->fetchColumn()],['Produksi',db()->query('SELECT COUNT(*) FROM production_headers')->fetchColumn()],['Penjualan ke toko',db()->query('SELECT COUNT(*) FROM kitchen_sales_headers')->fetchColumn()],['Kegiatan pegawai',db()->query('SELECT COUNT(*) FROM employee_activities')->fetchColumn()]]; echo '<div class="grid">'; foreach($stats as $s) echo '<div class="card"><div class="muted">'.e($s[0]).'</div><div class="stat">'.e($s[1]).'</div></div>'; echo '</div>';
}
elseif($page==='stores'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=$_POST['act']??'';
  if($act==='save'){
   $id=(int)($_POST['id']??0);
   $tokenInput=postval('api_token');
   $params=[postval('store_code'),postval('store_name'),postval('api_base_url'),isset($_POST['is_active'])?1:0,postval('notes')];
   if($id>0){
    if($tokenInput!==''){
     $paramsWithToken=[postval('store_code'),postval('store_name'),postval('api_base_url'),$tokenInput,isset($_POST['is_active'])?1:0,postval('notes'),$id];
     execq('UPDATE stores SET store_code=?,store_name=?,api_base_url=?,api_token=?,is_active=?,notes=? WHERE id=?',$paramsWithToken);
    }else{
     $params[]=$id;
     execq('UPDATE stores SET store_code=?,store_name=?,api_base_url=?,is_active=?,notes=? WHERE id=?',$params);
    }
    flash('Toko/API diperbarui.');
   }else{
    $paramsWithToken=[postval('store_code'),postval('store_name'),postval('api_base_url'),$tokenInput,isset($_POST['is_active'])?1:0,postval('notes')];
    execq('INSERT INTO stores(store_code,store_name,api_base_url,api_token,is_active,notes) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),api_base_url=VALUES(api_base_url),api_token=IF(VALUES(api_token)<>"",VALUES(api_token),api_token),is_active=VALUES(is_active),notes=VALUES(notes)',$paramsWithToken);
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
 echo '<form method="post" class="form-grid compact-form store-form">'.csrf_field().'<input type="hidden" name="act" value="save"><input type="hidden" name="id" value="'.(int)($edit['id']??0).'"><p><label>Kode Toko<input name="store_code" value="'.e($edit['store_code']??'').'" required></label></p><p><label>Nama Toko<input name="store_name" value="'.e($edit['store_name']??'').'" required></label></p><p><label>Base URL API Toko<input name="api_base_url" value="'.e($edit['api_base_url']??'').'" placeholder="https://toko.adena.co.id" required></label></p><p><label>Token API Toko<input name="api_token" value="" placeholder="Kosongkan jika tidak diganti" autocomplete="off"></label></p><p><label>Catatan<input name="notes" value="'.e($edit['notes']??'').'"></label></p><p><label class="check-inline"><input type="checkbox" name="is_active" '.((!$edit||!empty($edit['is_active']))?'checked':'').' > Aktif</label></p><p class="actions store-save-actions"><button class="btn">'.($edit?'Update':'Simpan').'</button>'.($edit?' <a class="btn light" href="?page=stores">Batal</a>':'').'</p></form>';
 echo '<div id="store-api-status" class="store-api-status" hidden></div>';
 echo '<div class="table-scroll"><table class="compact-table stores-table"><tr><th>Kode</th><th>Nama</th><th>API</th><th>Status</th><th>Aksi</th></tr>'; foreach(all("SELECT * FROM stores WHERE NOT (store_code LIKE 'HOPE-%' OR COALESCE(notes,'') LIKE '%HOPe%') ORDER BY store_name") as $r){echo '<tr><td>'.e($r['store_code']).'</td><td>'.e($r['store_name']).'</td><td class="api-url-cell">'.e($r['api_base_url']).'</td><td>'.($r['is_active']?'Aktif':'Nonaktif').'</td><td><div class="actions"><form method="post" data-store-api-test="1">'.csrf_field().'<input type="hidden" name="act" value="test_ping"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light" data-loading-text="Ping...">Ping</button></form><form method="post" data-store-api-test="1">'.csrf_field().'<input type="hidden" name="act" value="test_products"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light" data-loading-text="Test...">Test Produk</button></form><form method="post" data-store-api-test="1">'.csrf_field().'<input type="hidden" name="act" value="test_transfer"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light" data-loading-text="Test...">Test Transfer</button></form><a class="btn light" href="?page=stores&edit='.(int)$r['id'].'">Edit</a><form method="post" onsubmit="return confirm(&quot;Hapus/nonaktifkan toko ini?&quot;)">'.csrf_field().'<input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; } echo '</table></div>';
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
  if(($_POST['act']??'')==='import'){ flash('Import produk sekarang memakai proses AJAX agar pilihan Toko/HOPe aman. Klik tombol Impor Produk lagi.', 'err'); redirect('?page=finished'); }
 }
 h2('Produk Jadi / Finished Product');
 $activeStores=all("SELECT * FROM stores WHERE is_active=1 AND NOT (store_code LIKE 'HOPE-%' OR COALESCE(notes,'') LIKE '%HOPe%') ORDER BY store_name");
 $activeHopeConnections=table_exists('api_connections')?all("SELECT * FROM api_connections WHERE status='active' AND (remote_system_type='hope' OR connection_type='hope') ORDER BY connection_name, id DESC"):[];
 $hasImportSource=(count($activeStores)+count($activeHopeConnections))>0;
 $finishedRows=all('SELECT fp.*, s.store_name, s.notes AS source_notes FROM finished_products fp LEFT JOIN stores s ON s.id=fp.source_store_id WHERE fp.is_active=1 ORDER BY fp.name');
 echo '<div class="finished-toolbar card"><div class="finished-filter-grid"><p class="finished-search-field"><label>Search<input type="search" data-finished-search placeholder="Cari produk / SKU / sumber..."></label></p><p><label>Sumber<select data-finished-source><option value="">Semua sumber</option><option value="manual">Manual</option><option value="hope">HOPe/HP</option>'; foreach($activeStores as $s) echo '<option value="store:'.(int)$s['id'].'">'.e($s['store_name']).'</option>'; echo '</select></label></p><p><label>Status stok<select data-finished-stock><option value="">Semua stok</option><option value="available">Stok tersedia</option><option value="empty">Stok kosong</option></select></label></p><div class="finished-toolbar-actions"><button class="btn light" type="button" data-finished-filter-reset>Reset</button><button class="btn secondary" type="button" data-finished-import-open'.(!$hasImportSource?' disabled':'').'>Impor Produk</button></div></div></div>';
 echo '<div class="finished-table-wrap"><table class="finished-products-table" data-finished-table><thead><tr><th class="col-product">Produk</th><th class="col-sku">SKU</th><th class="col-price">Harga Jual Dapur</th><th class="col-stock">Stok Dapur</th><th class="col-source">Sumber</th><th class="col-action">Aksi</th></tr></thead><tbody>'; foreach($finishedRows as $r){ $stock=(float)stock_qty('finished',(int)$r['id']); $payloadSource=(string)($r['source_payload_json']??''); $isHopeProduct=empty($r['source_store_id']) && (stripos((string)($r['source_product_id']??''),'HOPE:')===0 || stripos($payloadSource,'"_source_system":"hope"')!==false || stripos($payloadSource,'HOPe')!==false); $sourceLabel=$isHopeProduct?'HOPe/HP':(string)($r['store_name']??'Manual'); if(!empty($r['source_notes']) && stripos((string)$r['source_notes'],'HOPe')!==false) $sourceLabel='HOPe/HP - '.$sourceLabel; $sourceKey=$isHopeProduct?'hope':(!empty($r['source_store_id'])?'store:'.(int)$r['source_store_id']:'manual'); $isActive=!empty($r['is_active'])?'1':'0'; $searchText=trim(($r['name']??'').' '.($r['sku']??'').' '.($r['code']??'').' '.($r['category']??'').' '.($r['unit']??'').' '.$sourceLabel.' '.rupiah($r['transfer_price']).' '.dec($stock)); echo '<tr data-finished-row data-search="'.e(strtolower($searchText)).'" data-source="'.e($sourceKey).'" data-stock="'.($stock>0?'available':'empty').'" data-active="'.$isActive.'"><td class="product-name-cell">'.e($r['name']).'</td><td>'.e($r['sku']).'</td><td>'.rupiah($r['transfer_price']).'</td><td>'.dec($stock).'</td><td>'.e($sourceLabel).'</td><td><div class="actions"><button type="button" class="btn light" data-finished-edit data-id="'.(int)$r['id'].'" data-code="'.e($r['code']??'').'" data-sku="'.e($r['sku']??'').'" data-name="'.e($r['name']??'').'" data-category="'.e($r['category']??'').'" data-unit="'.e($r['unit']??'pack').'" data-transfer-price="'.e((string)($r['transfer_price']??'0')).'" data-active="'.$isActive.'">Edit</button>'; echo (can_manage_finished_delete()?'<form method="post" onsubmit="return confirm(\'Hapus produk ini? Bila sudah ada histori, produk akan di-hide dari daftar.\')">'.csrf_field().'<input type="hidden" name="act" value="delete_finished"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form>':'').'</div></td></tr>'; } echo '<tr data-finished-empty hidden><td colspan="6" class="muted">Tidak ada produk yang sesuai filter.</td></tr></tbody></table></div>';
 echo '<div class="modal-backdrop" data-finished-import-modal hidden><div class="modal-card finished-import-modal" role="dialog" aria-modal="true" aria-labelledby="finished-import-title"><div class="modal-head"><div><h3 id="finished-import-title">Impor Produk</h3><div class="muted small">Ambil produk jadi dari Toko atau HOPe/HP. Harga jual remote tidak diimpor.</div></div><button type="button" class="modal-close" data-finished-import-close aria-label="Tutup">&times;</button></div><form method="post" class="finished-import-form product-import-form" data-product-import="1" data-import-url="import_products_ajax.php">'.csrf_field().'<input type="hidden" name="act" value="import"><p><label>Sumber Import<select name="source_type" data-import-source-type required><option value="store">Toko/API</option><option value="hope">HOPe/HP</option></select></label></p><p data-import-store-field><label>Toko<select name="store_id">'; if(count($activeStores)<1){ echo '<option value="">Belum ada toko aktif</option>'; } foreach($activeStores as $s) echo '<option value="'.(int)$s['id'].'">'.e($s['store_name']).'</option>'; echo '</select></label></p><p data-import-hope-field hidden><label>Koneksi HOPe/HP<select name="connection_id">'; if(count($activeHopeConnections)<1){ echo '<option value="">Belum ada koneksi HOPe aktif</option>'; } foreach($activeHopeConnections as $hc) echo '<option value="'.(int)$hc['id'].'">'.e(($hc['connection_name']?:'HOPe/HP').' - '.$hc['remote_base_url']).'</option>'; echo '</select></label><span class="muted small">Hanya product_type finished_good yang masuk ke Produk Jadi dan bisa dibuat BOM.</span></p><div class="import-progress" id="product-import-progress" hidden><div class="import-progress-top"><strong>Status import</strong><span data-import-percent>0%</span></div><div class="import-progress-track"><div class="import-progress-bar" data-import-bar></div></div><div class="muted small" data-import-status>Menunggu proses import...</div></div><div class="modal-actions"><button class="btn secondary" type="submit"'.(!$hasImportSource?' disabled':'').'>Impor Produk</button><button class="btn light" type="button" data-finished-import-close>Batal</button></div></form></div></div>';
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
elseif(in_array($page,['expenses','payment_requests','expense_categories'],true)){ require __DIR__.'/finance_module.php'; }
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
  foreach($_POST['transfer_item']??[] as $i=>$key){
   $item=dapur_item_by_key((string)$key); $qty=(float)($_POST['qty'][$i]??0); if(!$item||$qty<=0) continue;
   $stock=stock_qty($item['type'],(int)$item['id']);
   if($stock+0.0001<$qty){ flash('Stok tidak cukup untuk '.($item['name']??'item').'. Stok saat ini: '.dec($stock),'err'); redirect('?page=sales'); }
   $priceRaw=trim((string)($_POST['transfer_price'][$i]??'')); $price=$priceRaw===''?(float)$item['price']:(float)$priceRaw;
   $sub=$qty*$price; $total+=$sub;
   $rows[]=['item'=>$item,'qty'=>$qty,'price'=>$price,'subtotal'=>$sub];
  }
  if(count($rows)<1){ flash('Minimal 1 bahan/produk dan qty wajib diisi.','err'); redirect('?page=sales'); }
  $no=next_no('KDS','kitchen_sales_headers','sale_no');
  try{
   db()->beginTransaction();
   execq('INSERT INTO kitchen_sales_headers(sale_no,sale_date,store_id,sale_type,status,notes,created_by,posted_at) VALUES(?,?,?,?,?,?,?,NOW())',[$no,postval('sale_date',date('Y-m-d')),(int)$store['id'],'store_distribution','posted',postval('notes'),(int)($u['id']??0)]);
   $sid=(int)db()->lastInsertId();
   foreach($rows as $r){ $it=$r['item']; $finishedId=$it['type']==='finished'?(int)$it['id']:0; execq('INSERT INTO kitchen_sales_items(sale_id,item_type,item_ref_id,item_name,finished_product_id,qty,unit,transfer_price,subtotal) VALUES(?,?,?,?,?,?,?,?,?)',[$sid,$it['type'],(int)$it['id'],$it['name'],$finishedId,$r['qty'],$it['unit'],$r['price'],$r['subtotal']]); add_ledger($it['type'],(int)$it['id'],'sale_to_store','kitchen_sales_headers',$sid,0,(float)$r['qty'],(float)$r['price'],$no,(int)($u['id']??0)); }
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
 h2('Pengiriman Stok ke HOPe/Toko'); $catalog=dapur_transfer_catalog(); echo '<form method="post" class="compact-form">'.csrf_field().'<input type="hidden" name="act" value="create_transfer"><div class="form-grid"><p><label>Tanggal<input name="sale_date" type="date" value="'.date('Y-m-d').'"></label></p><p><label>Tujuan<select name="store_id">'; foreach(all("SELECT * FROM stores WHERE is_active=1 AND NOT (store_code LIKE 'HOPE-%' OR COALESCE(notes,'') LIKE '%HOPe%') ORDER BY store_name") as $s) echo '<option value="'.(int)$s['id'].'">'.e($s['store_name']).'</option>'; echo '</select></label></p><p><label>Catatan<input name="notes"></label></p></div><div class="table-scroll"><table class="compact-table"><tr><th>Bahan/Produk</th><th>Qty</th><th>Harga Transfer</th><th>Stok</th></tr>'; for($i=0;$i<8;$i++){ echo '<tr><td><select name="transfer_item[]"><option value="">-</option>'; foreach($catalog as $it) echo '<option value="'.e($it['key']).'">'.e(($it['type']==='raw'?'[Bahan] ':'[Produk] ').$it['name']).'</option>'; echo '</select></td><td><input name="qty[]" type="number" step="0.0001"></td><td><input name="transfer_price[]" type="number" step="0.01" placeholder="otomatis"></td><td class="muted">lihat menu Stok</td></tr>'; } echo '</table></div><p><button class="btn">Posting & Kirim Stok</button></p></form>'; echo '<h3>Riwayat Transfer</h3><table><tr><th>No</th><th>Tujuan</th><th>Total</th><th>Status</th><th>Detail API</th><th>Aksi</th></tr>'; foreach(all('SELECT h.*,s.store_name FROM kitchen_sales_headers h LEFT JOIN stores s ON s.id=h.store_id ORDER BY h.id DESC LIMIT 30') as $r){ $detail=trim((string)($r['remote_response']??'')); echo '<tr><td>'.e($r['sale_no']).'</td><td>'.e($r['store_name']).'</td><td>'.rupiah($r['total_amount']).'</td><td><span class="badge">'.e($r['status']).'</span></td><td>'.($detail!==''?'<details><summary>Lihat</summary><pre class="log-pre">'.e(short_text($detail,1200)).'</pre></details>':'-').'</td><td>'.($r['status']==='failed_sync'?'<form method="post">'.csrf_field().'<input type="hidden" name="act" value="resync_transfer"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light">Kirim Ulang</button></form>':'-').'</td></tr>'; } echo '</table>';
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
  $act=(string)($_POST['act']??'');
  if($act==='emp'){ ensure_dapur_employee_role_column(); $rk=postval('role_key','pegawai_dapur'); if(!in_array($rk,['owner','admin_dapur','manager_dapur','pegawai_dapur'],true)) $rk='pegawai_dapur'; execq('INSERT INTO employees(employee_name,phone,role_key,is_active) VALUES(?,?,?,1)',[postval('employee_name'),postval('phone'),$rk]); flash('Pegawai disimpan.'); redirect('?page=activities');}
  if($act==='delete_emp'){ $eid=(int)($_POST['employee_id']??0); if($eid>0){ execq('UPDATE employees SET is_active=0 WHERE id=?',[$eid]); flash('Pegawai dihapus dari daftar aktif.'); } redirect('?page=activities'); }
  if($act==='type'){execq('INSERT INTO activity_types(activity_name,category,unit_name,point_weight,is_active) VALUES(?,?,?,?,1)',[postval('activity_name'),postval('category'),postval('unit_name','kegiatan'),(float)postval('point_weight','1')]); flash('Jenis kegiatan disimpan.'); redirect('?page=activity_types');}
  if($act==='bulk_activity'){
   $activityDate=postval('activity_date',date('Y-m-d'));
   $employeeId=(int)($_POST['employee_id']??0);
   $dateValid=(bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',$activityDate);
   $employee=$employeeId>0?one('SELECT id FROM employees WHERE id=? AND is_active=1',[$employeeId]):null;
   $typeIds=is_array($_POST['activity_type_id']??null)?$_POST['activity_type_id']:[];
   $qtys=is_array($_POST['qty']??null)?$_POST['qty']:[];
   $notes=is_array($_POST['notes']??null)?$_POST['notes']:[];
   if(!$dateValid || !$employee){ flash('Tanggal atau pegawai tidak valid.','err'); redirect('?page=activities'); }
   $rows=[];
   foreach($typeIds as $i=>$rawType){
    $typeId=(int)$rawType;
    if($typeId<=0) continue;
    $at=one('SELECT id,point_weight FROM activity_types WHERE id=? AND is_active=1',[$typeId]);
    if(!$at){ flash('Ada jenis kegiatan yang tidak valid atau sudah nonaktif.','err'); redirect('?page=activities'); }
    $qty=(float)($qtys[$i]??1);
    if($qty<=0){ flash('Jumlah kegiatan harus lebih dari nol.','err'); redirect('?page=activities'); }
    $weight=(float)$at['point_weight'];
    $rows[]=[$activityDate,$employeeId,(int)$at['id'],$qty,$weight,$qty*$weight,trim((string)($notes[$i]??'')),(int)($u['id']??0)];
   }
   if(!$rows){ flash('Pilih minimal satu kegiatan untuk dicatat.','err'); redirect('?page=activities'); }
   $pdo=db();
   try{
    $pdo->beginTransaction();
    $st=$pdo->prepare('INSERT INTO employee_activities(activity_date,employee_id,activity_type_id,qty,point_weight,total_points,notes,created_by) VALUES(?,?,?,?,?,?,?,?)');
    foreach($rows as $row) $st->execute($row);
    $pdo->commit();
    flash(count($rows).' kegiatan pegawai berhasil dicatat.');
   }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); flash('Kegiatan gagal disimpan. Tidak ada data parsial yang dicatat.','err'); }
   redirect('?page=activities');
  }
 }
 h2('Kegiatan Pegawai & Bobot Poin');
 echo '<h3>Tambah Pegawai</h3><form method="post" class="actions">'.csrf_field().'<input type="hidden" name="act" value="emp"><input name="employee_name" placeholder="Nama pegawai" required><input name="phone" placeholder="HP"><select name="role_key"><option value="pegawai_dapur">Pegawai Dapur</option><option value="manager_dapur">Manajer Dapur</option><option value="admin_dapur">Admin Dapur</option><option value="owner">Owner</option></select><button class="btn light">Simpan Pegawai</button></form>';
 echo '<div class="actions"><a class="btn light" href="?page=activity_types">Daftar / Edit Kegiatan Pegawai</a></div>';
 $ym=preg_match('/^\d{4}-\d{2}$/',(string)($_GET['month']??''))?$_GET['month']:date('Y-m'); $start=$ym.'-01'; $end=date('Y-m-t',strtotime($start));
 echo '<h3>Total Poin Bulanan Pegawai</h3><form method="get" class="actions no-print"><input type="hidden" name="page" value="activities"><label>Bulan<input type="month" name="month" value="'.e($ym).'"></label><button class="btn light">Filter</button></form>';
 echo '<table><tr><th>Pegawai</th><th>Role</th><th>Total Poin</th><th>Jumlah Aktivitas</th><th>Aksi</th></tr>'; foreach(all('SELECT e.id,e.employee_name,COALESCE(e.role_key,\'pegawai_dapur\') role_key,COALESCE(SUM(ea.total_points),0) total_points,COUNT(ea.id) activity_count FROM employees e LEFT JOIN employee_activities ea ON ea.employee_id=e.id AND ea.activity_date BETWEEN ? AND ? WHERE e.is_active=1 GROUP BY e.id,e.employee_name,e.role_key ORDER BY total_points DESC,e.employee_name',[$start,$end]) as $er){ $detailUrl='?page=activities&detail_employee='.(int)$er['id'].'&from='.e($start).'&to='.e($end).'&month='.e($ym); echo '<tr><td>'.e($er['employee_name']).'</td><td>'.e(dapur_role_label((string)($er['role_key']??'pegawai_dapur'))).'</td><td>'.dec($er['total_points']).'</td><td>'.(int)$er['activity_count'].'</td><td><div class="actions mini"><a class="btn light" href="'.$detailUrl.'">Detail</a><form method="post" onsubmit="return confirm(&quot;Hapus pegawai ini dari daftar aktif? Riwayat KPI tetap disimpan.&quot;)">'.csrf_field().'<input type="hidden" name="act" value="delete_emp"><input type="hidden" name="employee_id" value="'.(int)$er['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; } echo '</table>';
 $detailEmployeeId=(int)($_GET['detail_employee']??0);
 if($detailEmployeeId>0){
  $detailEmployee=one('SELECT id,employee_name,COALESCE(role_key,\'pegawai_dapur\') role_key FROM employees WHERE id=?',[$detailEmployeeId]);
  $dateOk=fn($v)=>is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$v);
  $detailFrom=$dateOk($_GET['from']??null)?(string)$_GET['from']:$start;
  $detailTo=$dateOk($_GET['to']??null)?(string)$_GET['to']:$end;
  if($detailFrom>$detailTo){ [$detailFrom,$detailTo]=[$detailTo,$detailFrom]; }
  if($detailEmployee){
   $detailRows=all('SELECT ea.activity_date,ea.qty,ea.point_weight,ea.total_points,ea.notes,a.activity_name,a.category,a.unit_name FROM employee_activities ea JOIN activity_types a ON a.id=ea.activity_type_id WHERE ea.employee_id=? AND ea.activity_date BETWEEN ? AND ? ORDER BY ea.activity_date ASC,ea.id ASC',[$detailEmployeeId,$detailFrom,$detailTo]);
   $detailTotal=0.0; foreach($detailRows as $dr) $detailTotal+=(float)$dr['total_points'];
   $base='?page=activities&detail_employee='.$detailEmployeeId.'&month='.rawurlencode($ym);
   $month1Start=date('Y-m-01'); $month1End=date('Y-m-t'); $month2Start=date('Y-m-01',strtotime('-1 month')); $month3Start=date('Y-m-01',strtotime('-2 months'));
   $ci=company_info();
   echo '<div class="employee-modal-backdrop" data-employee-modal><section class="employee-modal-card" role="dialog" aria-modal="true" aria-label="Detail kegiatan pegawai"><div class="employee-modal-head no-print"><strong>Detail Kegiatan Pegawai</strong><a class="employee-modal-close" href="?page=activities&month='.e($ym).'" aria-label="Tutup">×</a></div><div class="employee-modal-scroll">';
   echo '<section class="employee-report"><div class="report-toolbar no-print"><a class="btn light" href="'.$base.'&from='.$month1Start.'&to='.$month1End.'">Bulan Ini</a><a class="btn light" href="'.$base.'&from='.$month2Start.'&to='.$month1End.'">2 Bulan</a><a class="btn light" href="'.$base.'&from='.$month3Start.'&to='.$month1End.'">3 Bulan</a><button type="button" class="btn" onclick="document.body.classList.add(\'print-employee-report\');window.print()">Print / Simpan PDF</button><a class="btn light" href="?page=activities&month='.e($ym).'">Tutup</a></div>';
   echo '<form method="get" class="actions report-filter no-print"><input type="hidden" name="page" value="activities"><input type="hidden" name="detail_employee" value="'.$detailEmployeeId.'"><input type="hidden" name="month" value="'.e($ym).'"><label>Dari<input type="date" name="from" value="'.e($detailFrom).'"></label><label>Sampai<input type="date" name="to" value="'.e($detailTo).'"></label><button class="btn light">Terapkan</button></form>';
   echo '<div class="report-letterhead"><img class="company-report-logo" src="'.e(company_logo_url()).'" alt="Logo"><div><div class="adena-logo-text">'.e($ci['name']).'</div>'.($ci['branch']!==''?'<div><strong>'.e($ci['branch']).'</strong></div>':'').'<div class="muted">'.e($ci['address']).'</div><div class="muted small">'.e(trim($ci['phone'].($ci['phone']!==''&&$ci['email']!==''?' • ':'').$ci['email'])).'</div></div></div><h2 class="report-title">Detail Total Poin Bulanan Pegawai</h2>';
   echo '<div class="report-meta"><div><span>Nama Pegawai</span><strong>'.e($detailEmployee['employee_name']).'</strong></div><div><span>Jabatan</span><strong>'.e(dapur_role_label((string)$detailEmployee['role_key'])).'</strong></div><div><span>Periode</span><strong>'.e(date('d-m-Y',strtotime($detailFrom)).' s.d. '.date('d-m-Y',strtotime($detailTo))).'</strong></div><div><span>Total Poin</span><strong>'.dec($detailTotal).'</strong></div><div><span>Jumlah Aktivitas</span><strong>'.count($detailRows).'</strong></div></div>';
   echo '<table class="employee-detail-table"><tr><th>No.</th><th>Tanggal</th><th>Kegiatan</th><th>Kategori</th><th>Qty</th><th>Satuan</th><th>Bobot</th><th>Total Poin</th><th>Catatan</th></tr>';
   foreach($detailRows as $i=>$dr){ echo '<tr><td>'.($i+1).'</td><td>'.e(date('d-m-Y',strtotime($dr['activity_date']))).'</td><td>'.e($dr['activity_name']).'</td><td>'.e($dr['category']?:'-').'</td><td>'.dec($dr['qty']).'</td><td>'.e($dr['unit_name']?:'-').'</td><td>'.dec($dr['point_weight']).'</td><td>'.dec($dr['total_points']).'</td><td>'.e($dr['notes']?:'-').'</td></tr>'; }
   if(!$detailRows) echo '<tr><td colspan="9" class="muted">Tidak ada kegiatan pada periode ini.</td></tr>';
   echo '<tr class="report-total-row"><td colspan="7"><strong>Total</strong></td><td><strong>'.dec($detailTotal).'</strong></td><td></td></tr></table><div class="report-signatures"><div>Mengetahui,<br><br><br><strong>Manajer Dapur</strong></div><div>Diperiksa oleh,<br><br><br><strong>________________</strong></div></div></section></div></section></div>';
  }
 }
 $activityOptions='<option value="">— Pilih kegiatan —</option>'; foreach(all('SELECT id,activity_name,point_weight FROM activity_types WHERE is_active=1 ORDER BY activity_name') as $a) $activityOptions.='<option value="'.(int)$a['id'].'">'.e($a['activity_name'].' ('.$a['point_weight'].' poin)').'</option>';
 echo '<h3>Input Kegiatan</h3><form method="post" class="bulk-activity-form" data-bulk-activity-form>'.csrf_field().'<input type="hidden" name="act" value="bulk_activity"><div class="bulk-activity-header"><label>Tanggal<input name="activity_date" type="date" value="'.date('Y-m-d').'" required></label><label>Pegawai<select name="employee_id" required><option value="">— Pilih pegawai —</option>'; foreach(all('SELECT * FROM employees WHERE is_active=1 ORDER BY employee_name') as $emp) echo '<option value="'.(int)$emp['id'].'">'.e($emp['employee_name']).'</option>'; echo '</select></label></div><div class="bulk-activity-table"><div class="bulk-activity-row bulk-activity-labels"><span>Kegiatan</span><span>Qty</span><span>Catatan</span><span>Aksi</span></div><div data-activity-rows>';
 for($i=0;$i<4;$i++) echo '<div class="bulk-activity-row" data-activity-row><select name="activity_type_id[]">'.$activityOptions.'</select><input name="qty[]" type="number" min="0.0001" step="0.0001" value="1"><input name="notes[]" placeholder="Catatan (opsional)"><button type="button" class="btn light" data-remove-activity-row>Hapus</button></div>';
 echo '</div></div><template data-activity-row-template><div class="bulk-activity-row" data-activity-row><select name="activity_type_id[]">'.$activityOptions.'</select><input name="qty[]" type="number" min="0.0001" step="0.0001" value="1"><input name="notes[]" placeholder="Catatan (opsional)"><button type="button" class="btn light" data-remove-activity-row>Hapus</button></div></template><div class="actions"><button type="button" class="btn light" data-add-activity-row>+ Tambah Kegiatan</button><button class="btn" type="submit">Catat Semua Kegiatan</button></div></form>';
}
elseif($page==='remuneration'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $name=postval('period_name');$start=postval('start_date');$end=postval('end_date');$fund=(float)postval('total_fund','0'); execq('INSERT INTO remuneration_periods(period_name,start_date,end_date,total_fund,status) VALUES(?,?,?,?,?)',[$name,$start,$end,$fund,'draft']); $pid=(int)db()->lastInsertId(); $rows=all('SELECT employee_id,SUM(total_points) pts FROM employee_activities WHERE activity_date BETWEEN ? AND ? GROUP BY employee_id',[$start,$end]); $total=array_sum(array_map(fn($r)=>(float)$r['pts'],$rows)); foreach($rows as $r){$pct=$total>0?(float)$r['pts']/$total:0; execq('INSERT INTO remuneration_items(period_id,employee_id,total_points,point_percent,amount) VALUES(?,?,?,?,?)',[$pid,(int)$r['employee_id'],(float)$r['pts'],$pct,$pct*$fund]);} flash('Perhitungan remunerasi dibuat.'); redirect('?page=remuneration'); }
 h2('Remunerasi'); echo '<form method="post" class="form-grid">'.csrf_field().'<p><label>Nama Periode<input name="period_name" value="Remun '.date('M Y').'"></label></p><p><label>Awal<input name="start_date" type="date" required></label></p><p><label>Akhir<input name="end_date" type="date" required></label></p><p><label>Total Dana<input name="total_fund" type="number"></label></p><p><button class="btn">Hitung</button></p></form>'; foreach(all('SELECT * FROM remuneration_periods ORDER BY id DESC LIMIT 10') as $p){echo '<h3>'.e($p['period_name']).' <span class="muted">'.e($p['start_date'].' s.d. '.$p['end_date']).'</span></h3><table><tr><th>Pegawai</th><th>Poin</th><th>%</th><th>Nominal</th></tr>'; foreach(all('SELECT ri.*,e.employee_name FROM remuneration_items ri JOIN employees e ON e.id=ri.employee_id WHERE period_id=? ORDER BY amount DESC',[(int)$p['id']]) as $r) echo '<tr><td>'.e($r['employee_name']).'</td><td>'.dec($r['total_points']).'</td><td>'.number_format((float)$r['point_percent']*100,2).'%</td><td>'.rupiah($r['amount']).'</td></tr>'; echo '</table>'; }
}
elseif($page==='company_settings'){
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $act=(string)($_POST['act']??'save_company');
  if($act==='restore_logo'){
   $old=trim((string)setting('company_logo',''));
   if($old!=='' && preg_match('/^company-logo-[A-Za-z0-9._-]+$/',$old) && is_file(__DIR__.'/../storage/'.$old)) @unlink(__DIR__.'/../storage/'.$old);
   set_setting('company_logo',''); flash('Logo dikembalikan ke logo bawaan Adena.'); redirect('?page=company_settings');
  }
  foreach(['company_name','company_branch','company_address','company_phone','company_email','company_extra'] as $key) set_setting($key,postval($key));
  if(isset($_FILES['company_logo']) && (int)($_FILES['company_logo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
   $file=$_FILES['company_logo'];
   if((int)$file['error']!==UPLOAD_ERR_OK) { flash('Upload logo gagal.','err'); redirect('?page=company_settings'); }
   if((int)$file['size']>2*1024*1024) { flash('Ukuran logo maksimal 2 MB.','err'); redirect('?page=company_settings'); }
   $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=(string)$finfo->file($file['tmp_name']); $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??'';
   if($ext===''){ flash('Logo harus berformat JPG, PNG, atau WEBP.','err'); redirect('?page=company_settings'); }
   $name='company-logo-'.bin2hex(random_bytes(8)).'.'.$ext; $target=__DIR__.'/../storage/'.$name;
   if(!move_uploaded_file($file['tmp_name'],$target)){ flash('Logo tidak dapat disimpan. Periksa izin folder storage.','err'); redirect('?page=company_settings'); }
   $old=trim((string)setting('company_logo','')); set_setting('company_logo',$name);
   if($old!=='' && $old!==$name && preg_match('/^company-logo-[A-Za-z0-9._-]+$/',$old) && is_file(__DIR__.'/../storage/'.$old)) @unlink(__DIR__.'/../storage/'.$old);
  }
  flash('Data perusahaan berhasil diperbarui.'); redirect('?page=company_settings');
 }
 $ci=company_info(); h2('Edit Perusahaan');
 echo '<p class="muted">Data ini dipakai untuk identitas Dapur Adena dan kop laporan kegiatan pegawai. Logo bawaan Adena tetap tersedia dan dapat dipulihkan kapan saja.</p><div class="company-settings-grid"><div><form method="post" enctype="multipart/form-data" class="form-grid compact-form">'.csrf_field().'<input type="hidden" name="act" value="save_company"><p><label>Nama Perusahaan<input name="company_name" value="'.e($ci['name']).'" required></label></p><p><label>Nama Cabang / Toko<input name="company_branch" value="'.e($ci['branch']).'"></label></p><p class="wide"><label>Alamat<textarea name="company_address" rows="3">'.e($ci['address']).'</textarea></label></p><p><label>Nomor Telepon<input name="company_phone" value="'.e($ci['phone']).'"></label></p><p><label>Email<input name="company_email" type="email" value="'.e($ci['email']).'"></label></p><p class="wide"><label>Informasi Tambahan<textarea name="company_extra" rows="3">'.e($ci['extra']).'</textarea></label></p><p class="wide"><label>Ganti Logo<input name="company_logo" type="file" accept="image/jpeg,image/png,image/webp"><small class="muted">JPG, PNG, atau WEBP. Maksimal 2 MB.</small></label></p><p><button class="btn">Simpan Perusahaan</button></p></form></div><div class="company-logo-preview"><span class="muted">Logo aktif</span><img src="'.e(company_logo_url()).'" alt="Logo perusahaan"><form method="post">'.csrf_field().'<input type="hidden" name="act" value="restore_logo"><button class="btn light" type="submit">Kembalikan Logo Bawaan Adena</button></form></div></div>';
}
elseif($page==='users'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $hash=password_hash(postval('password'),PASSWORD_DEFAULT); execq('INSERT INTO users(username,name,email,password_hash,role_id,is_active) VALUES(?,?,?,?,?,1)',[postval('username'),postval('name'),postval('email'),$hash,(int)$_POST['role_id']]); flash('User dibuat.'); redirect('?page=users'); }
 h2('User & Role'); echo '<form method="post" class="form-grid">'.csrf_field().'<p><label>Username<input name="username" required></label></p><p><label>Nama<input name="name" required></label></p><p><label>Email<input name="email"></label></p><p><label>Password<input name="password" type="password" required></label></p><p><label>Role<select name="role_id">'; foreach(all("SELECT * FROM roles WHERE role_key NOT IN ('superadmin','kepala_dapur','kasir_dapur','viewer') ORDER BY FIELD(role_key,'owner','admin_dapur','manager_dapur','pegawai_dapur'), id") as $r) echo '<option value="'.(int)$r['id'].'">'.e(dapur_role_label((string)$r['role_key'])).'</option>'; echo '</select></label></p><p><button class="btn">Buat User</button></p></form><table><tr><th>Username</th><th>Nama</th><th>Role</th></tr>'; foreach(all('SELECT u.*,r.role_key,r.role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id') as $r) echo '<tr><td>'.e($r['username']).'</td><td>'.e($r['name']).'</td><td>'.e(dapur_role_label((string)$r['role_key'])).'</td></tr>'; echo '</table>';
}

elseif($page==='error_log'){
 if(!is_owner()){ http_response_code(403); die('Akses ditolak.'); }
 h2('Error Log API');
 echo '<p class="muted">Log ini khusus owner. Error dari test API, import produk, transfer stok, dan sync gagal akan masuk ke sini.</p>';
 if(!table_exists('api_logs')){ echo '<div class="notice err">Tabel api_logs belum ada.</div>'; }
 else{
  $scope=$_GET['scope']??'error'; $storeFilter=(int)($_GET['store_id']??0); $endpointFilter=trim((string)($_GET['endpoint']??''));
  echo '<form method="get" class="form-grid compact-form"><input type="hidden" name="page" value="error_log"><p><label>Tampilan<select name="scope"><option value="error" '.($scope==='error'?'selected':'').'>Error saja</option><option value="all" '.($scope==='all'?'selected':'').'>Semua log</option></select></label></p><p><label>Toko<select name="store_id"><option value="0">Semua toko</option>'; foreach(all("SELECT * FROM stores WHERE NOT (store_code LIKE 'HOPE-%' OR COALESCE(notes,'') LIKE '%HOPe%') ORDER BY store_name") as $st) echo '<option value="'.(int)$st['id'].'" '.($storeFilter===(int)$st['id']?'selected':'').'>'.e($st['store_name']).'</option>'; echo '</select></label></p><p><label>Endpoint mengandung<input name="endpoint" value="'.e($endpointFilter).'"></label></p><p><button class="btn light">Filter</button></p></form>';
  $where=[]; $params=[];
  if($scope!=='all') $where[]="(LOWER(al.status) LIKE '%fail%' OR LOWER(al.status) LIKE '%error%' OR LOWER(al.status) LIKE '%invalid%' OR LOWER(al.status) IN ('failed_sync','import_failed','transfer_test_failed','product_test_failed','ping_failed'))";
  if($storeFilter>0){ $where[]='al.store_id=?'; $params[]=$storeFilter; }
  if($endpointFilter!==''){ $where[]='al.endpoint LIKE ?'; $params[]='%'.$endpointFilter.'%'; }
  $sql='SELECT al.*,s.store_name FROM api_logs al LEFT JOIN stores s ON s.id=al.store_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY al.id DESC LIMIT 200';
  echo '<table><tr><th>Waktu</th><th>Toko</th><th>Endpoint</th><th>Status</th><th>Pesan</th><th>Payload/Response</th></tr>';
  foreach(all($sql,$params) as $r){ $payload=trim((string)($r['payload_json']??'')); echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['store_name']??'-').'</td><td><span class="code">'.e($r['endpoint']).'</span></td><td><span class="badge">'.e($r['status']).'</span></td><td>'.e($r['message']).'</td><td>'.($payload!==''?'<details><summary>Lihat</summary><pre class="log-pre">'.e(short_text($payload,1800)).'</pre></details>':'-').'</td></tr>'; }
  echo '</table>';
 }
 if(table_exists('api_connection_test_logs')){
  echo '<h3>Log Test Koneksi</h3><table><tr><th>Waktu</th><th>Target</th><th>Endpoint</th><th>HTTP</th><th>Status</th><th>Pesan</th><th>Response</th></tr>';
  foreach(all('SELECT * FROM api_connection_test_logs ORDER BY id DESC LIMIT 100') as $tl){ $rb=trim((string)($tl['response_body']??'')); echo '<tr><td>'.e($tl['created_at']).'</td><td>'.e($tl['target_base_url']).'</td><td><span class="code">'.e($tl['endpoint']).'</span></td><td>'.e((string)$tl['http_status']).'</td><td><span class="badge">'.e($tl['status']).'</span></td><td>'.e($tl['message']).'</td><td>'.($rb!==''?'<details><summary>Lihat</summary><pre class="log-pre">'.e(short_text($rb,1800)).'</pre></details>':'-').'</td></tr>'; }
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
 $roles=all("SELECT * FROM roles WHERE role_key NOT IN ('owner','superadmin','kepala_dapur','kasir_dapur','viewer') ORDER BY FIELD(role_key,'admin_dapur','manager_dapur','pegawai_dapur'), id");
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
elseif($page==='hope_connection'){
 ensure_api_pairing_schema(); ensure_hope_transfer_schema();
 h2('Koneksi ke HOPe');
 $msg=trim((string)($_GET['msg']??'')); if($msg!=='') echo '<div class="notice ok">'.e($msg).'</div>';
 echo '<p class="muted">Menu khusus koneksi Dapur ke HOPe. Isi website HOPe, approve di HOPe, lalu klik Cek Status. Setelah aktif, gunakan Test Koneksi dan Test Transfer Stok. Semua test transfer adalah dry-run.</p>';
 echo '<div class="grid"><div class="card"><h3>Hubungkan ke HOPe</h3><form method="post" action="api_pairing_action.php" class="form-grid compact-form">'.csrf_field().'<input type="hidden" name="act" value="create_request"><input type="hidden" name="target_type" value="hope"><input type="hidden" name="return_page" value="hope_connection"><p><label>Nama Koneksi<input name="connection_name" value="HOPe POS System" required></label></p><p><label>Website HOPe<input name="base_url" placeholder="https://hope.domain.com" required></label></p><p><button class="btn">Hubungkan ke HOPe</button></p></form></div><div class="card"><h3>Test Tanpa Transaksi</h3><p class="muted">Test koneksi memastikan token/pairing valid. Test transfer stok mengirim payload dry-run ke HOPe sehingga stok tidak berubah.</p><a class="btn light" href="?page=error_log">Error Log</a> <a class="btn light" href="?page=api_integrations">Koneksi & API</a></div></div>';
 $incoming=table_exists('api_pairing_requests')?all("SELECT * FROM api_pairing_requests WHERE direction='incoming' AND requester_type IN ('hope','HOPe','external') AND status='pending' ORDER BY id DESC LIMIT 30"):[];
 $outgoing=table_exists('api_pairing_requests')?all("SELECT * FROM api_pairing_requests WHERE direction='outgoing' AND target_type='hope' AND ".api_ui_active_status_sql('status')." ORDER BY id DESC LIMIT 30"):[];
 $conns=table_exists('api_connections')?all("SELECT * FROM api_connections WHERE (LOWER(COALESCE(remote_system_type,'')) IN ('hope','hp','hope_pos','pos') OR LOWER(COALESCE(connection_type,'')) IN ('hope','hp','hope_pos','pos') OR LOWER(COALESCE(connection_name,'')) LIKE '%hope%' OR LOWER(COALESCE(connection_name,'')) LIKE '%hp%' OR LOWER(COALESCE(connection_name,'')) LIKE '%pos%') AND ".api_ui_active_status_sql('status')." ORDER BY id DESC LIMIT 30"):[];
 echo '<h3>Koneksi HOPe Aktif</h3><table><tr><th>Nama</th><th>URL</th><th>Scope</th><th>Status</th><th>Terakhir Test</th><th>Aksi</th></tr>';
 foreach($conns as $c){ echo '<tr><td>'.e($c['connection_name']).'</td><td>'.e($c['remote_base_url']).'</td><td>'.e($c['access_scope']).'</td><td>'.status_badge2($c['status']).'</td><td>'.e($c['last_test_at']??'-').'<br><small>'.e($c['last_test_message']??'').'</small></td><td>'.api_connection_test_actions($c,'hope_connection').'</td></tr>'; }
 if(!$conns) echo '<tr><td colspan="6" class="muted">Belum ada koneksi HOPe aktif.</td></tr>'; echo '</table>';
 echo '<h3>Request Keluar ke HOPe</h3><table><tr><th>Waktu</th><th>URL HOPe</th><th>Status</th><th>Pesan</th><th>Aksi</th></tr>';
 foreach($outgoing as $r){ echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['target_base_url']).'</td><td>'.status_badge2($r['status']).'</td><td>'.e($r['last_message']).'</td><td><div class="actions mini"><form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="hope_connection"><input type="hidden" name="act" value="check_status"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light">Cek Status</button></form><form method="post" action="api_pairing_action.php" onsubmit="return confirm(&quot;Hapus request ini?&quot;)">'.csrf_field().'<input type="hidden" name="return_page" value="hope_connection"><input type="hidden" name="act" value="delete_request"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; }
 if(!$outgoing) echo '<tr><td colspan="5" class="muted">Belum ada request keluar.</td></tr>'; echo '</table>';
 echo '<h3>Request Masuk dari HOPe</h3><table><tr><th>Waktu</th><th>Peminta</th><th>URL</th><th>Scope</th><th>Status</th><th>Aksi</th></tr>';
 foreach($incoming as $r){ echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['requester_name']).'</td><td>'.e($r['requester_base_url']).'</td><td>'.e($r['requested_scope']).'</td><td>'.status_badge2($r['status']).'</td><td><div class="actions mini">'; if($r['status']==='pending'){ echo '<form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="hope_connection"><input type="hidden" name="act" value="approve"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn">Approve</button></form><form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="hope_connection"><input type="hidden" name="act" value="reject"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Reject</button></form>'; } echo '<form method="post" action="api_pairing_action.php" onsubmit="return confirm(&quot;Hapus request ini?&quot;)">'.csrf_field().'<input type="hidden" name="return_page" value="hope_connection"><input type="hidden" name="act" value="delete_request"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; }
 if(!$incoming) echo '<tr><td colspan="6" class="muted">Belum ada request masuk.</td></tr>'; echo '</table>';
}
elseif($page==='api_integrations'){
 ensure_pairing_notification_columns(); ensure_hope_transfer_schema();
 h2('Koneksi & API');
 $msg=trim((string)($_GET['msg']??'')); if($msg!=='') echo '<div class="notice ok">'.e($msg).'</div>';
 echo '<p class="muted">Satu halaman untuk status API Dapur: koneksi HOPe, API toko/cabang, pairing request, test dry-run, dan error log. Menu ini berada di bawah Admin agar tidak bercampur dengan transaksi harian.</p>';
 echo '<div class="actions"><a class="btn" href="?page=hope_connection">Koneksi ke HOPe</a><a class="btn light" href="?page=stores">Kelola Toko & API</a><a class="btn light" href="?page=error_log">Error Log</a><a class="btn light" href="?page=api">API Token Manual</a></div>';
 $conns=table_exists('api_connections')?all("SELECT * FROM api_connections WHERE ".api_ui_active_status_sql('status')." ORDER BY FIELD(status,'active','pending'), id DESC LIMIT 80"):[];
 echo '<h3>Status Koneksi Aplikasi</h3><table><tr><th>Jenis</th><th>Nama</th><th>Website</th><th>Scope</th><th>Status</th><th>Terakhir Test</th><th>Aksi</th></tr>';
 foreach($conns as $c){ echo '<tr><td>'.api_ui_type_label($c).'</td><td>'.e($c['connection_name']).'</td><td>'.e($c['remote_base_url']).'</td><td>'.e($c['access_scope']).'</td><td>'.status_badge2($c['status']).'</td><td>'.e($c['last_test_at']??'-').'<br><small>'.e($c['last_test_message']??'').'</small></td><td>'.api_connection_test_actions($c,'api_integrations').'</td></tr>'; }
 if(!$conns) echo '<tr><td colspan="7" class="muted">Belum ada koneksi aplikasi.</td></tr>'; echo '</table>';
 $stores=table_exists('stores')?all("SELECT * FROM stores WHERE NOT (store_code LIKE 'HOPE-%' OR COALESCE(notes,'') LIKE '%HOPe%') ORDER BY store_name"):[];
 echo '<h3>Status API Toko / Cabang</h3><table><tr><th>Kode</th><th>Nama</th><th>Website</th><th>Status</th><th>Last Sync</th><th>Aksi Test</th></tr>';
 foreach($stores as $st){ echo '<tr><td>'.e($st['store_code']).'</td><td>'.e($st['store_name']).'</td><td>'.e($st['api_base_url']).'</td><td>'.($st['is_active']?status_badge2('active'):status_badge2('inactive')).'</td><td>'.e($st['last_sync_at']??'-').'</td><td><div class="actions mini"><form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="store_test_ping"><input type="hidden" name="id" value="'.(int)$st['id'].'"><button class="btn light">Test Ping</button></form><form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="store_test_products"><input type="hidden" name="id" value="'.(int)$st['id'].'"><button class="btn light">Test Produk</button></form><form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="store_test_transfer"><input type="hidden" name="id" value="'.(int)$st['id'].'"><button class="btn light">Test Transfer Stok</button></form><a class="btn light" href="?page=error_log&store_id='.(int)$st['id'].'">Log</a></div></td></tr>'; }
 if(!$stores) echo '<tr><td colspan="6" class="muted">Belum ada API toko/cabang.</td></tr>'; echo '</table>';
 $incoming=table_exists('api_pairing_requests')?all("SELECT * FROM api_pairing_requests WHERE direction='incoming' AND status='pending' ORDER BY id DESC LIMIT 50"):[];
 $outgoing=table_exists('api_pairing_requests')?all("SELECT * FROM api_pairing_requests WHERE direction='outgoing' AND ".api_ui_active_status_sql('status')." ORDER BY id DESC LIMIT 50"):[];
 echo '<h3>Request Pairing Masuk</h3><table><tr><th>Waktu</th><th>Peminta</th><th>Jenis</th><th>URL</th><th>Scope</th><th>Status</th><th>Aksi</th></tr>';
 foreach($incoming as $r){ echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['requester_name']).'</td><td>'.e($r['requester_type']).'</td><td>'.e($r['requester_base_url']).'</td><td>'.e($r['requested_scope']).'</td><td>'.status_badge2($r['status']).'</td><td><div class="actions mini">'; if($r['status']==='pending'){ echo '<form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="approve"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn">Approve</button></form><form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="reject"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Reject</button></form>'; } echo '<form method="post" action="api_pairing_action.php" onsubmit="return confirm(&quot;Hapus request ini?&quot;)">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="delete_request"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; }
 if(!$incoming) echo '<tr><td colspan="7" class="muted">Belum ada request masuk.</td></tr>'; echo '</table>';
 echo '<h3>Request Pairing Keluar</h3><table><tr><th>Waktu</th><th>Tujuan</th><th>URL</th><th>Scope</th><th>Status</th><th>Pesan</th><th>Aksi</th></tr>';
 foreach($outgoing as $r){ echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['target_name']).'</td><td>'.e($r['target_base_url']).'</td><td>'.e($r['requested_scope']).'</td><td>'.status_badge2($r['status']).'</td><td>'.e($r['last_message']).'</td><td><div class="actions mini"><form method="post" action="api_pairing_action.php">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="check_status"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn light">Cek Status</button></form><form method="post" action="api_pairing_action.php" onsubmit="return confirm(&quot;Hapus request ini?&quot;)">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="delete_request"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger">Hapus</button></form></div></td></tr>'; }
 if(!$outgoing) echo '<tr><td colspan="7" class="muted">Belum ada request keluar.</td></tr>'; echo '</table>';
 echo '<h3>Buat Request Pairing Baru</h3><form method="post" action="api_pairing_action.php" class="form-grid compact-form">'.csrf_field().'<input type="hidden" name="return_page" value="api_integrations"><input type="hidden" name="act" value="create_request"><p><label>Nama Koneksi<input name="connection_name" placeholder="HOPe / Back Office" required></label></p><p><label>URL Tujuan<input name="base_url" placeholder="https://domain-tujuan.com" required></label></p><p><label>Jenis Tujuan<select name="target_type"><option value="hope">HOPe</option><option value="backoffice">Back Office</option><option value="adena_store">Toko Adena</option><option value="dapur">Dapur</option></select></label></p><p><button class="btn">Kirim Request</button></p></form>';
}
elseif($page==='api'){
 if($_SERVER['REQUEST_METHOD']==='POST'){ $plain='kd_'.bin2hex(random_bytes(24)); execq('INSERT INTO api_tokens(token_name,token_hash,permissions_json,is_active) VALUES(?,?,?,1)',[postval('token_name'),hash('sha256',$plain),json_encode(['products.view','stock.view','*'])]); flash('Token dibuat. Simpan token ini sekarang: '.$plain); redirect('?page=api'); }
 h2('API Token Dapur'); echo '<p class="muted">Token ini untuk integrasi eksternal bila diperlukan. Token toko untuk menerima kiriman dapur diatur di menu Toko & API.</p><form method="post" class="actions">'.csrf_field().'<input name="token_name" placeholder="Nama token" required><button class="btn">Generate Token</button></form><table><tr><th>Nama</th><th>Status</th><th>Terakhir Dipakai</th></tr>'; foreach(all('SELECT * FROM api_tokens ORDER BY id DESC') as $r) echo '<tr><td>'.e($r['token_name']).'</td><td>'.($r['is_active']?'Aktif':'Nonaktif').'</td><td>'.e($r['last_used_at']).'</td></tr>'; echo '</table>';
}
?></div></main></div><script>window.addEventListener("afterprint",function(){document.body.classList.remove("print-employee-report");});</script></body></html>
