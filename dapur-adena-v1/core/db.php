<?php
declare(strict_types=1);
function app_config(): array {
  static $cfg=null; if($cfg!==null) return $cfg;
  $p=__DIR__.'/../config.php';
  if(!is_file($p)){ header('Location: install/index.php'); exit; }
  $cfg=require $p; return $cfg;
}
function db(): PDO {
  static $pdo=null; if($pdo) return $pdo;
  $c=app_config()['db'];
  $dsn="mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
  $pdo=new PDO($dsn,$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  return $pdo;
}
