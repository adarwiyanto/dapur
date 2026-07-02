<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('employees.read');

function dapur_employee_role_label(string $roleKey): string {
  $roleKey=strtolower(trim($roleKey));
  return match($roleKey){
    'owner' => 'Owner',
    'admin_dapur' => 'Admin Dapur',
    'manager_dapur','kepala_dapur' => 'Manajer Dapur',
    default => 'Pegawai Dapur',
  };
}

$rows=[];
try{
  try { db()->exec("ALTER TABLE employees ADD COLUMN role_key VARCHAR(50) NOT NULL DEFAULT 'pegawai_dapur' AFTER phone"); } catch(Throwable $e) {}
  try {
    db()->exec("UPDATE employees e JOIN users u ON LOWER(TRIM(u.name))=LOWER(TRIM(e.employee_name)) JOIN roles r ON r.id=u.role_id SET e.role_key=CASE WHEN r.role_key='owner' THEN 'owner' WHEN r.role_key='admin_dapur' THEN 'admin_dapur' WHEN r.role_key IN ('manager_dapur','kepala_dapur') THEN 'manager_dapur' ELSE 'pegawai_dapur' END WHERE e.role_key IS NULL OR e.role_key='' OR e.role_key='pegawai_dapur'");
  } catch(Throwable $e) {}
  $sql="SELECT e.id,e.employee_name,e.phone,COALESCE(NULLIF(e.role_key,''),'pegawai_dapur') role_key,e.is_active,COUNT(ea.id) activity_count,COALESCE(SUM(ea.total_points),0) total_points FROM employees e LEFT JOIN employee_activities ea ON ea.employee_id=e.id GROUP BY e.id,e.employee_name,e.phone,e.role_key,e.is_active ORDER BY e.employee_name";
  foreach(db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){
    $roleKey=(string)($r['role_key']??'pegawai_dapur');
    $rows[]=['source'=>'dapur','employee_id'=>(string)$r['id'],'name'=>(string)$r['employee_name'],'role_key'=>$roleKey,'role'=>dapur_employee_role_label($roleKey),'location'=>'Dapur','phone'=>(string)($r['phone']??''),'is_active'=>(int)($r['is_active']??1),'activity_count'=>(int)($r['activity_count']??0),'total_points'=>(float)($r['total_points']??0)];
  }
}catch(Throwable $e){}
pairing_ok(['data'=>$rows,'count'=>count($rows)]);
