<?php
$done=is_file(__DIR__.'/../config.php');
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $host=$_POST['host']??'localhost'; $port=$_POST['port']??'3306'; $db=$_POST['db']??''; $user=$_POST['user']??''; $pass=$_POST['pass']??''; $base=rtrim($_POST['base_url']??'', '/');
  $admin=$_POST['admin_user']??'superadmin'; $adminpass=$_POST['admin_pass']??''; $name=$_POST['admin_name']??'Superadmin';
  try{
    $pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");
    $sql=file_get_contents(__DIR__.'/../db/schema.sql');
    foreach(array_filter(array_map('trim', explode(';',$sql))) as $q){ if($q!=='') $pdo->exec($q); }
    $hash=password_hash($adminpass, PASSWORD_DEFAULT);
    $st=$pdo->prepare("INSERT INTO users(username,name,password_hash,role_id,is_active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role_id=1, is_active=1");
    $st->execute([$admin,$name,$hash,1]);
    $cfg="<?php\nreturn ".var_export(['app'=>['name'=>'Dapur Adena','base_url'=>$base], 'db'=>['host'=>$host,'port'=>$port,'name'=>$db,'user'=>$user,'pass'=>$pass]], true).";\n";
    file_put_contents(__DIR__.'/../config.php',$cfg);
    $done=true; $msg='Instalasi selesai. Hapus folder install setelah login pertama.';
  }catch(Throwable $e){ $msg='Error: '.$e->getMessage(); }
}
?><!doctype html><html><head><meta charset="utf-8"><title>Install Dapur Adena</title><link rel="stylesheet" href="../assets/app.css"></head><body><div class="login-box card"><h2>Installer Dapur Adena</h2><?php if($msg): ?><div class="notice <?=str_starts_with($msg,'Error')?'err':'ok'?>"><?=htmlspecialchars($msg)?></div><?php endif; ?><?php if($done): ?><p>Aplikasi sudah terpasang.</p><a class="btn" href="../login.php">Login</a><?php else: ?><form method="post"><div class="form-grid"><p><label>DB Host<input name="host" value="localhost"></label></p><p><label>Port<input name="port" value="3306"></label></p><p><label>DB Name<input name="db" required></label></p><p><label>DB User<input name="user" required></label></p><p><label>DB Password<input name="pass" type="password"></label></p><p><label>Base URL<input name="base_url" placeholder="https://dapur.adena.co.id" required></label></p><p><label>Admin Username<input name="admin_user" value="superadmin" required></label></p><p><label>Nama Admin<input name="admin_name" value="Superadmin" required></label></p><p><label>Password Admin<input name="admin_pass" type="password" required></label></p></div><button class="btn">Install</button></form><?php endif; ?></div></body></html>
