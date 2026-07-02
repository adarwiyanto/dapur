<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
$rows=[];
try{
  $sql="SELECT e.id,e.employee_name,e.phone,e.is_active,COUNT(ea.id) activity_count,COALESCE(SUM(ea.total_points),0) total_points FROM employees e LEFT JOIN employee_activities ea ON ea.employee_id=e.id GROUP BY e.id,e.employee_name,e.phone,e.is_active ORDER BY e.employee_name";
  foreach(db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){
    $rows[]=['source'=>'dapur','employee_id'=>(string)$r['id'],'name'=>(string)$r['employee_name'],'role'=>'Pegawai Dapur','location'=>'Dapur','phone'=>(string)($r['phone']??''),'is_active'=>(int)($r['is_active']??1),'activity_count'=>(int)($r['activity_count']??0),'total_points'=>(float)($r['total_points']??0)];
  }
}catch(Throwable $e){}
pairing_ok(['data'=>$rows,'count'=>count($rows)]);
