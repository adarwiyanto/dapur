<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function base_url(string $p=''): string { $b=rtrim(app_config()['app']['base_url'] ?? '', '/'); return $b.'/'.ltrim($p,'/'); }
function redirect(string $p): never { header('Location: '.$p); exit; }
function rupiah($n): string { return 'Rp '.number_format((float)$n,0,',','.'); }
function dec($n): string { return rtrim(rtrim(number_format((float)$n,4,'.',''), '0'), '.'); }
function now(): string { return date('Y-m-d H:i:s'); }
function setting(string $key, $default=null){ $st=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $st->execute([$key]); $v=$st->fetchColumn(); return $v===false?$default:$v; }
function set_setting(string $key, string $val): void { $st=db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'); $st->execute([$key,$val]); }
function csrf_token(): string { if(session_status()!==PHP_SESSION_ACTIVE) session_start(); if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void { if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){ if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')) { http_response_code(419); die('CSRF token tidak valid.'); } } }
function flash(?string $msg=null, string $type='ok'): ?array { if(session_status()!==PHP_SESSION_ACTIVE) session_start(); if($msg!==null){$_SESSION['flash']=[$msg,$type]; return null;} $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }
function table_exists(string $t): bool { $st=db()->prepare("SHOW TABLES LIKE ?"); $st->execute([$t]); return (bool)$st->fetchColumn(); }
function one(string $sql, array $p=[]){$st=db()->prepare($sql);$st->execute($p);return $st->fetch();}
function all(string $sql, array $p=[]): array {$st=db()->prepare($sql);$st->execute($p);return $st->fetchAll();}
function execq(string $sql, array $p=[]): int {$st=db()->prepare($sql);$st->execute($p);return $st->rowCount();}
function count_rows_if_table(string $table, string $where='', array $params=[]): int {
 if(!preg_match('/^[A-Za-z0-9_]+$/',$table) || !table_exists($table)) return 0;
 $sql='SELECT COUNT(*) c FROM '.$table;
 if(trim($where)!=='') $sql.=' WHERE '.$where;
 $r=one($sql,$params);
 return (int)($r['c']??0);
}
function stock_qty(string $type, int $id): float { $st=db()->prepare('SELECT COALESCE(SUM(qty_in-qty_out),0) FROM stock_ledger WHERE item_type=? AND item_id=?'); $st->execute([$type,$id]); return (float)$st->fetchColumn(); }
function stock_qty_map(string $type, array $ids=[]): array {
 $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));
 if(count($ids)<1) return [];
 $ph=implode(',',array_fill(0,count($ids),'?'));
 $params=array_merge([$type],$ids);
 $st=db()->prepare('SELECT item_id, COALESCE(SUM(qty_in-qty_out),0) qty FROM stock_ledger WHERE item_type=? AND item_id IN ('.$ph.') GROUP BY item_id');
 $st->execute($params);
 $map=[];
 foreach($st->fetchAll() as $r) $map[(int)$r['item_id']]=(float)$r['qty'];
 foreach($ids as $id) if(!array_key_exists($id,$map)) $map[$id]=0.0;
 return $map;
}
function add_ledger(string $type,int $id,string $trans,string $ref,int $refid,float $in,float $out,?float $cost,string $note='',?int $uid=null): void { $st=db()->prepare('INSERT INTO stock_ledger(item_type,item_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())'); $st->execute([$type,$id,$trans,$ref,$refid,$in,$out,$cost,$note,$uid]); }
