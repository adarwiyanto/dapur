<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/sales_calculator.php';

function pos_column_exists(string $table,string $column): bool {
  $st=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
  $st->execute([$table,$column]); return (bool)$st->fetchColumn();
}
function pos_index_exists(string $table,string $index): bool {
  $st=db()->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1');
  $st->execute([$table,$index]); return (bool)$st->fetchColumn();
}
function pos_ensure_schema(): void {
  static $done=false; if($done) return; $done=true;
  db()->exec("CREATE TABLE IF NOT EXISTS pos_api_sessions(
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id INT NOT NULL,device_id VARCHAR(120) NOT NULL,
    token_hash CHAR(64) NOT NULL,expires_at DATETIME NOT NULL,last_used_at DATETIME NULL,revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY uq_pos_token_hash(token_hash),
    KEY idx_pos_user_device(user_id,device_id),KEY idx_pos_expiry(expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $headerColumns=[
    'customer_id'=>"ALTER TABLE kitchen_sales_headers ADD COLUMN customer_id INT NULL AFTER store_id",
    'pos_uuid'=>"ALTER TABLE kitchen_sales_headers ADD COLUMN pos_uuid VARCHAR(40) NULL AFTER remote_response",
    'pos_device_id'=>"ALTER TABLE kitchen_sales_headers ADD COLUMN pos_device_id VARCHAR(120) NULL AFTER pos_uuid",
    'payment_method'=>"ALTER TABLE kitchen_sales_headers ADD COLUMN payment_method VARCHAR(30) NULL AFTER pos_device_id",
    'paid_amount'=>"ALTER TABLE kitchen_sales_headers ADD COLUMN paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER payment_method",
    'change_amount'=>"ALTER TABLE kitchen_sales_headers ADD COLUMN change_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER paid_amount",
    'pos_created_at'=>"ALTER TABLE kitchen_sales_headers ADD COLUMN pos_created_at DATETIME NULL AFTER change_amount",
  ];
  foreach($headerColumns as $name=>$sql){ if(!pos_column_exists('kitchen_sales_headers',$name)) try{db()->exec($sql);}catch(Throwable $e){} }
  $itemColumns=[
    'item_type'=>"ALTER TABLE kitchen_sales_items ADD COLUMN item_type VARCHAR(20) NOT NULL DEFAULT 'finished' AFTER sale_id",
    'item_ref_id'=>"ALTER TABLE kitchen_sales_items ADD COLUMN item_ref_id INT NULL AFTER item_type",
    'item_name'=>"ALTER TABLE kitchen_sales_items ADD COLUMN item_name VARCHAR(180) NULL AFTER item_ref_id",
    'original_price'=>"ALTER TABLE kitchen_sales_items ADD COLUMN original_price DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER transfer_price",
    'discount_type'=>"ALTER TABLE kitchen_sales_items ADD COLUMN discount_type VARCHAR(20) NOT NULL DEFAULT 'none' AFTER original_price",
    'discount_value'=>"ALTER TABLE kitchen_sales_items ADD COLUMN discount_value DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER discount_type",
    'discount_amount'=>"ALTER TABLE kitchen_sales_items ADD COLUMN discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount_value",
  ];
  foreach($itemColumns as $name=>$sql){ if(!pos_column_exists('kitchen_sales_items',$name)) try{db()->exec($sql);}catch(Throwable $e){} }
  if(!pos_index_exists('kitchen_sales_headers','uq_kitchen_sales_pos_uuid')) try{db()->exec('ALTER TABLE kitchen_sales_headers ADD UNIQUE KEY uq_kitchen_sales_pos_uuid(pos_uuid)');}catch(Throwable $e){}
}
pos_ensure_schema();

function pos_json(array $data,int $code=200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function pos_input(): array {
  $raw=file_get_contents('php://input');
  $j=json_decode($raw?:'{}',true);
  return is_array($j)?$j:[];
}
function pos_bearer(): string {
  $h=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
  return preg_match('/Bearer\s+(.+)/i',$h,$m)?trim($m[1]):'';
}
function pos_require_session(): array {
  $token=pos_bearer();
  if($token==='') pos_json(['ok'=>false,'error'=>'Sesi POS tidak ditemukan.'],401);
  $hash=hash('sha256',$token);
  $st=db()->prepare("SELECT s.*,u.username,u.name,u.role_id,u.is_active,r.role_key,r.role_name
    FROM pos_api_sessions s JOIN users u ON u.id=s.user_id JOIN roles r ON r.id=u.role_id
    WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>NOW() AND u.is_active=1 LIMIT 1");
  $st->execute([$hash]); $row=$st->fetch();
  if(!$row) pos_json(['ok'=>false,'error'=>'Sesi POS berakhir. Silakan login kembali saat online.'],401);
  execq('UPDATE pos_api_sessions SET last_used_at=NOW() WHERE id=?',[(int)$row['id']]);
  return $row;
}
function pos_user_can_sell(array $user): bool {
  if(in_array((string)($user['role_key']??''),['owner','superadmin','admin_dapur'],true)) return true;
  $st=db()->prepare("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key='sales_distribution' LIMIT 1");
  $st->execute([(int)$user['role_id']]);
  return (bool)$st->fetchColumn();
}
function pos_new_sale_no(): string {
  $prefix='POS-'.date('ymd').'-';
  for($i=0;$i<20;$i++){
    $candidate=$prefix.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
    if(!one('SELECT id FROM kitchen_sales_headers WHERE sale_no=?',[$candidate])) return $candidate;
  }
  return $prefix.date('His');
}
