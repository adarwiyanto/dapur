<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
function start_app_session(): void { if(session_status()!==PHP_SESSION_ACTIVE){ session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax']); session_start(); } }
function current_user(): ?array { start_app_session(); return $_SESSION['user']??null; }
function require_login(): void { start_app_session(); if(empty($_SESSION['user'])) redirect(base_url('login.php')); }
function is_owner(): bool { $u=current_user(); return in_array(($u['role_key']??''), ['owner','superadmin'], true); }
function is_superadmin(): bool { return is_owner(); }
function can(string $perm): bool { $u=current_user(); if(!$u) return false; if(in_array(($u['role_key']??''), ['owner','superadmin'], true)) return true; $st=db()->prepare('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.permission_key=?'); $st->execute([(int)$u['role_id'],$perm]); return (bool)$st->fetchColumn(); }
function require_perm(string $perm): void { require_login(); if(!can($perm)){ http_response_code(403); die('Akses ditolak.'); } }
function login_attempt(string $username,string $password): bool { $st=db()->prepare('SELECT u.*, r.role_key, r.role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=? AND u.is_active=1 LIMIT 1'); $st->execute([$username]); $u=$st->fetch(); if(!$u || !password_verify($password,(string)$u['password_hash'])) return false; start_app_session(); session_regenerate_id(true); unset($u['password_hash']); $_SESSION['user']=$u; execq('UPDATE users SET last_login_at=NOW() WHERE id=?',[(int)$u['id']]); return true; }
function logout(): void { start_app_session(); $_SESSION=[]; session_destroy(); }
