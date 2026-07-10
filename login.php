<?php
require_once __DIR__.'/core/auth.php';
start_app_session();
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $remember=isset($_POST['remember_me']) && $_POST['remember_me']==='1';
  if(login_attempt($_POST['username']??'',$_POST['password']??'',$remember)) redirect(base_url('admin/index.php'));
  $err='Login gagal.';
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Login Dapur Adena</title><link rel="stylesheet" href="assets/app.css">
<style>
.remember-row{display:flex;align-items:flex-start;gap:9px;margin:12px 0 6px}.remember-row input{width:auto;margin-top:3px}
.remember-warning{display:none;margin:8px 0 14px;padding:10px 12px;border:1px solid #f59e0b;border-radius:8px;background:rgba(245,158,11,.12);color:#92400e;font-size:.85rem;line-height:1.4}.remember-warning.show{display:block}
</style></head><body><div class="login-box card"><h2>Dapur Adena</h2><p class="muted">Produksi, BOM, stok, distribusi toko, kegiatan pegawai.</p>
<?php if($err): ?><div class="notice err"><?=e($err)?></div><?php endif; ?>
<form method="post"><p><label>Username<input name="username" autocomplete="username" autofocus required></label></p>
<p><label>Password<input name="password" type="password" autocomplete="current-password" required></label></p>
<label class="remember-row"><input id="remember-me" type="checkbox" name="remember_me" value="1"><span>Remember me</span></label>
<div id="remember-warning" class="remember-warning" role="alert">Gunakan fitur ini hanya pada komputer pribadi. Jangan aktifkan pada komputer bersama atau perangkat umum karena akun dapat dibuka tanpa memasukkan username dan password.</div>
<button class="btn">Login</button></form></div>
<script>(function(){var c=document.getElementById('remember-me'),w=document.getElementById('remember-warning');if(c&&w)c.addEventListener('change',function(){w.classList.toggle('show',c.checked);});})();</script>
</body></html>