<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
$month=trim((string)($_GET['month']??date('Y-m')));
if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=date('Y-m');
$start=$month.'-01'; $end=date('Y-m-t',strtotime($start));
$rows=[]; $total=0;
try{
  $sql="SELECT e.id,e.employee_name,e.phone,e.is_active,COUNT(ea.id) activity_count,COALESCE(SUM(ea.total_points),0) total_points FROM employees e LEFT JOIN employee_activities ea ON ea.employee_id=e.id AND ea.activity_date BETWEEN ? AND ? WHERE LOWER(COALESCE(e.role_key,'pegawai_dapur'))<>'owner' GROUP BY e.id,e.employee_name,e.phone,e.is_active ORDER BY total_points DESC,e.employee_name";
  $st=db()->prepare($sql); $st->execute([$start,$end]);
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $pts=(float)($r['total_points']??0); $total+=$pts; $rows[]=['source'=>'dapur','employee_id'=>(string)$r['id'],'name'=>(string)$r['employee_name'],'phone'=>(string)($r['phone']??''),'location'=>'Dapur','is_active'=>(int)($r['is_active']??1),'activity_count'=>(int)($r['activity_count']??0),'total_points'=>$pts]; }
}catch(Throwable $e){}
pairing_ok(['data'=>['month'=>$month,'start_date'=>$start,'end_date'=>$end,'total_points'=>$total,'employees'=>$rows],'count'=>count($rows)]);
