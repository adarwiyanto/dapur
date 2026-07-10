<?php
/** Automatic API Pairing Helper */
require_once __DIR__ . '/db.php';
if (is_file(__DIR__ . '/helpers.php')) require_once __DIR__ . '/helpers.php';

define('PAIRING_DEFAULT_EXP_HOURS', 48);
function pairing_table_exists(string $table): bool { try { $st=db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $st->execute([$table]); return (int)$st->fetchColumn()>0; } catch(Throwable $e){ return false; } }
function pairing_column_exists(string $table,string $col): bool { try { $st=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $st->execute([$table,$col]); return (int)$st->fetchColumn()>0; } catch(Throwable $e){ return false; } }
function ensure_api_pairing_schema(): void {
  static $done=false; if($done) return; $done=true; $pdo=db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS api_pairing_requests (id BIGINT AUTO_INCREMENT PRIMARY KEY, direction VARCHAR(20) NOT NULL DEFAULT 'incoming', request_code VARCHAR(90) NOT NULL, request_secret_hash VARCHAR(255) NULL, requester_name VARCHAR(180) NOT NULL, requester_type VARCHAR(50) NOT NULL, requester_base_url VARCHAR(255) NOT NULL, target_name VARCHAR(180) NULL, target_type VARCHAR(50) NOT NULL, target_base_url VARCHAR(255) NULL, requested_scope VARCHAR(80) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', callback_url VARCHAR(255) NULL, access_token_plain TEXT NULL, token_hash VARCHAR(255) NULL, reject_reason TEXT NULL, approved_by BIGINT NULL, approved_at DATETIME NULL, rejected_by BIGINT NULL, rejected_at DATETIME NULL, expires_at DATETIME NULL, last_checked_at DATETIME NULL, last_message TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, notification_dismissed_at DATETIME NULL, notification_dismissed_by BIGINT NULL, UNIQUE KEY uq_api_pairing_request_code (request_code), KEY idx_api_pairing_status (status), KEY idx_api_pairing_direction (direction)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS api_connections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, connection_name VARCHAR(180) NOT NULL, connection_type VARCHAR(50) NOT NULL, remote_system_type VARCHAR(50) NOT NULL, remote_base_url VARCHAR(255) NOT NULL, access_scope VARCHAR(80) NOT NULL, token_hash VARCHAR(255) NULL, token_plain TEXT NULL, access_token_plain TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'active', paired_from_request_code VARCHAR(90) NULL, paired_by BIGINT NULL, paired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_by BIGINT NULL, revoked_at DATETIME NULL, last_used_at DATETIME NULL, last_test_at DATETIME NULL, last_test_status VARCHAR(30) NULL, last_test_message TEXT NULL, notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, KEY idx_api_connections_type (connection_type), KEY idx_api_connections_remote_type (remote_system_type), KEY idx_api_connections_status (status), KEY idx_api_connections_request_code (paired_from_request_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS api_connection_test_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, connection_id BIGINT UNSIGNED NULL, target_base_url VARCHAR(255) NOT NULL, target_type VARCHAR(50) NULL, endpoint VARCHAR(255) NULL, status VARCHAR(30) NOT NULL, http_status INT NULL, message TEXT NULL, response_body LONGTEXT NULL, tested_by BIGINT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_api_connection_test_connection (connection_id), KEY idx_api_connection_test_status (status), KEY idx_api_connection_test_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, store_id INT NULL, endpoint VARCHAR(180) NULL, direction ENUM('in','out') DEFAULT 'out', status VARCHAR(40) NOT NULL, message TEXT NULL, payload_json LONGTEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY idx_api_logs_created (created_at), KEY idx_api_logs_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  foreach([
    ['api_connections','token_plain','TEXT NULL'],['api_connections','access_token_plain','TEXT NULL'],['api_connections','created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],['api_connections','updated_at','DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'],['api_connections','revoked_by','BIGINT NULL'],['api_connections','revoked_at','DATETIME NULL'],['api_connections','last_test_at','DATETIME NULL'],['api_connections','last_test_status','VARCHAR(30) NULL'],['api_connections','last_test_message','TEXT NULL'],
    ['api_pairing_requests','access_token_plain','TEXT NULL'],['api_pairing_requests','notification_dismissed_at','DATETIME NULL'],['api_pairing_requests','notification_dismissed_by','BIGINT NULL']
  ] as $c){ try{ if(!pairing_column_exists($c[0],$c[1])) $pdo->exec("ALTER TABLE `{$c[0]}` ADD COLUMN `{$c[1]}` {$c[2]}"); }catch(Throwable $e){} }
}
function pairing_json(array $data,int $code=200): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function pairing_ok(array $data=[]): void { pairing_json(array_merge(['ok'=>true],$data)); }
function pairing_err(string $msg,int $code=400,array $extra=[]): void { pairing_log_event(null,(string)($_SERVER['REQUEST_URI']??'api'),'in','api_error',$msg,$extra); pairing_json(array_merge(['ok'=>false,'message'=>$msg],$extra),$code); }
function pairing_input(): array { $raw=file_get_contents('php://input')?:''; $j=json_decode($raw,true); return is_array($j)?$j:($_POST?:[]); }
function pairing_bearer_token(): string { $h=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; if($h==='' && function_exists('apache_request_headers')){ foreach(apache_request_headers() as $k=>$v){ if(strtolower((string)$k)==='authorization'){ $h=(string)$v; break; } } } return preg_match('/^Bearer\s+(.+)$/i',trim($h),$m)?trim($m[1]):''; }
function pairing_normalize_url(string $url): string { $url=trim($url); $url=preg_replace('~\s+~','',$url) ?: $url; if($url!=='' && !preg_match('~^https?://~i',$url)) $url='https://'.$url; return rtrim($url,'/'); }
function pairing_request_code(string $prefix='PAIR'): string { return $prefix.'-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3))); }
function pairing_secret(): string { return bin2hex(random_bytes(32)); }
function pairing_allowed_scope(string $scope): bool { return in_array($scope,['readonly','products.read','stock.view','stock_transfer.write','dapur_stock_sender','store_product_readonly','web_readonly','admin_rw'],true); }
function pairing_scope_for(string $requesterType,string $targetType=''): string {
  $r=strtolower($requesterType); $t=strtolower($targetType);
  if($r==='backoffice') return 'admin_rw';
  if($r==='dapur' && $t==='hope') return 'dapur_stock_sender';
  if($r==='dapur') return 'dapur_stock_sender';
  if($r==='hope') return 'products.read';
  if($r==='adena_store' || $r==='toko' || $r==='store') return 'store_product_readonly';
  if($r==='web_external' || $r==='external') return 'web_readonly';
  return 'readonly';
}
function pairing_scope_allows(string $have,string $need): bool {
  if($have==='superadmin') return true;
  if($have==='admin_rw') return true;
  if($need==='' || $need==='readonly') return true;
  if($have===$need) return true;
  if($have==='dapur_stock_sender' && in_array($need,['products.read','stock_transfer.write','dapur_stock_sender','readonly'],true)) return true;
  if($have==='products.read' && in_array($need,['products.read','readonly'],true)) return true;
  if($have==='store_product_readonly' && in_array($need,['products.read','categories.read','images.read','store_product_readonly','readonly'],true)) return true;
  if($have==='web_readonly' && in_array($need,['products.read','categories.read','images.read','web_readonly','readonly'],true)) return true;
  return false;
}
function pairing_auth(string $need=''): array {
  ensure_api_pairing_schema(); $token=pairing_bearer_token(); if($token==='' || strlen($token)<20) pairing_err('Token pairing kosong/tidak valid.',401);
  $hash=hash('sha256',$token); $st=db()->prepare("SELECT * FROM api_connections WHERE status='active' AND token_hash=? ORDER BY id DESC LIMIT 1"); $st->execute([$hash]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row) pairing_err('Token pairing tidak dikenal.',401);
  if(!pairing_scope_allows((string)$row['access_scope'],$need)) pairing_err('Scope koneksi tidak mencukupi: '.$need,403,['scope'=>$row['access_scope']??'']);
  db()->prepare('UPDATE api_connections SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['id']]); return $row;
}
function pairing_remote_json(string $baseUrl,string $path,array $payload=[],string $method='POST',string $token='',int $timeout=18): array {
  $url=pairing_normalize_url($baseUrl).'/'.ltrim($path,'/'); if(strtoupper($method)==='GET' && $payload) $url.=(str_contains($url,'?')?'&':'?').http_build_query($payload);
  $headers=['Accept: application/json','Content-Type: application/json','User-Agent: Adena-Pairing/1.1']; if($token!=='') $headers[]='Authorization: Bearer '.$token;
  $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3]);
  if(strtoupper($method)!=='GET'){ curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method); curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
  $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  $json=is_string($body)?json_decode($body,true):null; if(!is_array($json)) $json=['ok'=>false,'message'=>$err!==''?$err:'Respons bukan JSON','raw'=>(string)$body];
  $json['_http_code']=$code; $json['_error']=$err; $json['_raw']=(string)$body; return $json;
}
function pairing_pending_count(): int { ensure_api_pairing_schema(); return (int)db()->query("SELECT COUNT(*) FROM api_pairing_requests WHERE direction='incoming' AND status='pending'")->fetchColumn(); }
function pairing_latest_notifications(int $limit=8): array { ensure_api_pairing_schema(); $st=db()->prepare("SELECT request_code,requester_name,requester_type,status,created_at,updated_at FROM api_pairing_requests WHERE direction='incoming' ORDER BY FIELD(status,'pending','approved','rejected'), id DESC LIMIT ?"); $st->bindValue(1,$limit,PDO::PARAM_INT); $st->execute(); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
function pairing_log_event(?int $storeId,string $endpoint,string $direction,string $status,string $message,$payload=null): void { try{ ensure_api_pairing_schema(); $json=null; if($payload!==null) $json=is_string($payload)?$payload:json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); db()->prepare('INSERT INTO api_logs(store_id,endpoint,direction,status,message,payload_json) VALUES(?,?,?,?,?,?)')->execute([$storeId,substr($endpoint,0,180),$direction,substr($status,0,40),$message,$json]); }catch(Throwable $e){} }
function pairing_test_log(?int $connectionId,string $target,string $targetType,string $endpoint,string $status,int $http,string $message,$response=null,?int $testedBy=null): void { try{ ensure_api_pairing_schema(); $body=is_string($response)?$response:json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); db()->prepare('INSERT INTO api_connection_test_logs(connection_id,target_base_url,target_type,endpoint,status,http_status,message,response_body,tested_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$connectionId,$target,$targetType,$endpoint,$status,$http,$message,$body,$testedBy]); }catch(Throwable $e){} }
function pairing_short($v,int $len=1200): string { $t=trim((string)$v); return strlen($t)>$len?substr($t,0,$len).'...':$t; }
?>
