<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
$rows=[];
try{
  foreach(db()->query("SELECT id,name,role,is_active FROM employees ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $r){ $rows[]=['source'=>'dapur','employee_id'=>(string)$r['id'],'name'=>(string)$r['name'],'role'=>(string)($r['role']??'Staff Dapur'),'location'=>'Dapur','is_active'=>(int)($r['is_active']??1)]; }
}catch(Throwable $e){}
pairing_ok(['data'=>$rows,'count'=>count($rows)]);
