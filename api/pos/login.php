<?php
declare(strict_types=1);
require_once __DIR__.'/../../core/pos_api.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') pos_json(['ok'=>false,'error'=>'Method tidak diizinkan.'],405);
$in=pos_input(); $username=trim((string)($in['username']??'')); $password=(string)($in['password']??''); $deviceId=trim((string)($in['device_id']??''));
if($username===''||$password===''||$deviceId==='') pos_json(['ok'=>false,'error'=>'Username, password, dan device diperlukan.'],422);
if(strlen($deviceId)>120) pos_json(['ok'=>false,'error'=>'Device ID tidak valid.'],422);
$st=db()->prepare('SELECT u.*,r.role_key,r.role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=? AND u.is_active=1 LIMIT 1');
$st->execute([$username]); $u=$st->fetch();
if(!$u || !password_verify($password,(string)$u['password_hash'])) pos_json(['ok'=>false,'error'=>'Username atau password salah.'],401);
if(!pos_user_can_sell($u)) pos_json(['ok'=>false,'error'=>'User tidak memiliki akses penjualan/POS.'],403);
$token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token); $expires=date('Y-m-d H:i:s',time()+60*60*24*180);
execq('UPDATE pos_api_sessions SET revoked_at=NOW() WHERE user_id=? AND device_id=? AND revoked_at IS NULL',[(int)$u['id'],$deviceId]);
execq('INSERT INTO pos_api_sessions(user_id,device_id,token_hash,expires_at,last_used_at,created_at) VALUES(?,?,?,?,NOW(),NOW())',[(int)$u['id'],$deviceId,$hash,$expires]);
execq('UPDATE users SET last_login_at=NOW() WHERE id=?',[(int)$u['id']]);
pos_json(['ok'=>true,'token'=>$token,'expires_at'=>$expires,'user'=>['id'=>(int)$u['id'],'username'=>(string)$u['username'],'name'=>(string)$u['name'],'role_key'=>(string)$u['role_key'],'role_name'=>(string)$u['role_name']]]);
