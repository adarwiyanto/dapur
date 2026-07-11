<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
$employeeId=(int)($_GET['employee_id']??0);$month=trim((string)($_GET['month']??date('Y-m')));if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');$start=$month.'-01';$end=date('Y-m-d',strtotime($start.' +1 month'));
try{
 $st=db()->prepare("SELECT id,employee_name,phone,COALESCE(role_key,'pegawai_dapur') role_key,is_active FROM employees WHERE id=? LIMIT 1");$st->execute([$employeeId]);$emp=$st->fetch(PDO::FETCH_ASSOC);if(!$emp)pairing_err('Pegawai tidak ditemukan.',404);
 $st=db()->prepare("SELECT ea.id,ea.activity_date,at.activity_name,at.category,ea.qty,ea.point_weight,ea.total_points,ea.notes FROM employee_activities ea JOIN activity_types at ON at.id=ea.activity_type_id WHERE ea.employee_id=? AND ea.activity_date>=? AND ea.activity_date<? ORDER BY ea.activity_date,ea.id");$st->execute([$employeeId,$start,$end]);$items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$total=0;foreach($items as $r)$total+=(float)$r['total_points'];
 $logo=trim((string)setting('company_logo',''));$logoUrl=$logo!==''?base_url('storage/'.rawurlencode($logo)):base_url('assets/adena-default.jpg');
 pairing_ok(['data'=>['system'=>['type'=>'dapur','name'=>(string)setting('company_name','Dapur Adena'),'logo_url'=>$logoUrl],'employee'=>['employee_id'=>(string)$emp['id'],'name'=>$emp['employee_name'],'role_key'=>$emp['role_key'],'is_active'=>(int)$emp['is_active']],'assessment'=>['month'=>$month,'status'=>'final','total_points'=>$total,'activity_count'=>count($items),'items'=>$items]]]);
}catch(Throwable $e){pairing_err('Gagal membaca detail KPI Dapur: '.$e->getMessage(),500);}
