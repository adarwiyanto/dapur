<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';

const REMEMBER_LOGIN_LIFETIME = 315360000;

function start_app_session(): void {
  if(session_status()!==PHP_SESSION_ACTIVE){
    $secure=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
    session_set_cookie_params(['path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
  }
}
function remember_cookie_name(): string { return 'DAPUR_ADENA_REMEMBER'; }
function remember_cookie_secure(): bool {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
}
function ensure_remember_login_table(): void {
  db()->exec("CREATE TABLE IF NOT EXISTS user_remember_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    selector CHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    password_fingerprint CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    UNIQUE KEY uq_user_remember_selector(selector),
    KEY idx_user_remember_user(user_id),
    KEY idx_user_remember_expires(expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function remember_set_cookie(string $value,int $expires): void {
  if(headers_sent()) return;
  setcookie(remember_cookie_name(),$value,['expires'=>$expires,'path'=>'/','secure'=>remember_cookie_secure(),'httponly'=>true,'samesite'=>'Lax']);
}
function remember_forget_current_device(): void {
  $raw=(string)($_COOKIE[remember_cookie_name()]??''); $parts=explode(':',$raw,2);
  if(count($parts)===2 && preg_match('/^[a-f0-9]{24}$/',$parts[0])){
    try { ensure_remember_login_table(); execq('DELETE FROM user_remember_tokens WHERE selector=?',[$parts[0]]); } catch(Throwable $e){}
  }
  unset($_COOKIE[remember_cookie_name()]); remember_set_cookie('',time()-42000);
}
function remember_issue_for_user(int $userId,string $passwordHash): void {
  remember_forget_current_device(); ensure_remember_login_table();
  try { db()->exec('DELETE FROM user_remember_tokens WHERE expires_at<=NOW()'); } catch(Throwable $e){}
  $selector=bin2hex(random_bytes(12)); $validator=bin2hex(random_bytes(32)); $expires=time()+REMEMBER_LOGIN_LIFETIME;
  execq('INSERT INTO user_remember_tokens(user_id,selector,validator_hash,password_fingerprint,expires_at) VALUES(?,?,?,?,?)',[
    $userId,$selector,hash('sha256',$validator),hash('sha256',$passwordHash),date('Y-m-d H:i:s',$expires)
  ]);
  remember_set_cookie($selector.':'.$validator,$expires);
}
function remember_restore_user(): ?array {
  static $attempted=false; if($attempted) return null; $attempted=true;
  $raw=(string)($_COOKIE[remember_cookie_name()]??''); $parts=explode(':',$raw,2);
  if(count($parts)!==2 || !preg_match('/^[a-f0-9]{24}$/',$parts[0]) || !preg_match('/^[a-f0-9]{64}$/',$parts[1])){
    if($raw!=='') remember_forget_current_device(); return null;
  }
  try {
    ensure_remember_login_table();
    $st=db()->prepare('SELECT t.id token_id,t.validator_hash,t.password_fingerprint,u.*,r.role_key,r.role_name
      FROM user_remember_tokens t JOIN users u ON u.id=t.user_id JOIN roles r ON r.id=u.role_id
      WHERE t.selector=? AND t.expires_at>NOW() AND u.is_active=1 LIMIT 1');
    $st->execute([$parts[0]]); $u=$st->fetch();
    $valid=$u && hash_equals((string)$u['validator_hash'],hash('sha256',$parts[1]))
      && hash_equals((string)$u['password_fingerprint'],hash('sha256',(string)$u['password_hash']));
    if(!$valid){ remember_forget_current_device(); return null; }
    $tokenId=(int)$u['token_id']; unset($u['token_id'],$u['validator_hash'],$u['password_fingerprint'],$u['password_hash']);
    $expires=time()+REMEMBER_LOGIN_LIFETIME;
    execq('UPDATE user_remember_tokens SET expires_at=?,last_used_at=NOW() WHERE id=?',[date('Y-m-d H:i:s',$expires),$tokenId]);
    remember_set_cookie($raw,$expires); session_regenerate_id(true); $_SESSION['user']=$u; return $u;
  } catch(Throwable $e){ return null; }
}
function current_user(): ?array {
  start_app_session();
  if(empty($_SESSION['user'])) remember_restore_user();
  return $_SESSION['user']??null;
}
function require_login(): void { start_app_session(); if(!current_user()) redirect(base_url('login.php')); }
function is_owner(): bool { $u=current_user(); return in_array(($u['role_key']??''),['owner','superadmin'],true); }
function is_superadmin(): bool { return is_owner(); }
function can(string $perm): bool {
  $u=current_user(); if(!$u) return false; if(in_array(($u['role_key']??''),['owner','superadmin'],true)) return true;
  $st=db()->prepare('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key=?');
  $st->execute([(int)$u['role_id'],$perm]); return (bool)$st->fetchColumn();
}
function require_perm(string $perm): void { require_login(); if(!can($perm)){ http_response_code(403); die('Akses ditolak.'); } }
function login_attempt(string $username,string $password,bool $remember=false): bool {
  $st=db()->prepare('SELECT u.*,r.role_key,r.role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=? AND u.is_active=1 LIMIT 1');
  $st->execute([$username]); $u=$st->fetch();
  if(!$u || !password_verify($password,(string)$u['password_hash'])) return false;
  $passwordHash=(string)$u['password_hash']; start_app_session(); session_regenerate_id(true); unset($u['password_hash']); $_SESSION['user']=$u;
  if($remember) remember_issue_for_user((int)$u['id'],$passwordHash); else remember_forget_current_device();
  execq('UPDATE users SET last_login_at=NOW() WHERE id=?',[(int)$u['id']]); return true;
}
function logout(): void { start_app_session(); remember_forget_current_device(); $_SESSION=[]; session_destroy(); }
