<?php
require_once __DIR__ . '/api_pairing.php';

function bo_resource_registry(): array {
  return [
    'stores'=>['table'=>'stores','pk'=>'id','read'=>'stores.read','write'=>'stores.write'],
    'products'=>['table'=>'finished_products','pk'=>'id','read'=>'products.read','write'=>'products.write'],
    'product_mappings'=>['table'=>'finished_product_store_mappings','pk'=>'id','read'=>'products.read','write'=>'products.write'],
    'product_import_logs'=>['table'=>'product_import_logs','pk'=>'id','read'=>'products.read','write'=>null],
    'raw_materials'=>['table'=>'raw_materials','pk'=>'id','read'=>'raw_materials.read','write'=>'raw_materials.write'],
    'purchases'=>['table'=>'purchase_headers','pk'=>'id','read'=>'purchases.read','write'=>'purchases.write'],
    'purchase_items'=>['table'=>'purchase_items','pk'=>'id','read'=>'purchases.read','write'=>'purchases.write'],
    'boms'=>['table'=>'bom_headers','pk'=>'id','read'=>'bom.read','write'=>'bom.write'],
    'bom_items'=>['table'=>'bom_items','pk'=>'id','read'=>'bom.read','write'=>'bom.write'],
    'productions'=>['table'=>'production_headers','pk'=>'id','read'=>'production.read','write'=>'production.write'],
    'production_items'=>['table'=>'production_items','pk'=>'id','read'=>'production.read','write'=>'production.write'],
    'stock_ledger'=>['table'=>'stock_ledger','pk'=>'id','read'=>'stock.read','write'=>null],
    'stock_opnames'=>['table'=>'stock_opname_headers','pk'=>'id','read'=>'stock_opname.read','write'=>'stock_opname.write'],
    'stock_opname_items'=>['table'=>'stock_opname_items','pk'=>'id','read'=>'stock_opname.read','write'=>'stock_opname.write'],
    'stock_transfers'=>['table'=>'kitchen_sales_headers','pk'=>'id','read'=>'stock_transfers.read','write'=>'stock_transfers.write'],
    'stock_transfer_items'=>['table'=>'kitchen_sales_items','pk'=>'id','read'=>'stock_transfers.read','write'=>'stock_transfers.write'],
    'activity_types'=>['table'=>'activity_types','pk'=>'id','read'=>'activities.read','write'=>'activities.write'],
    'employees'=>['table'=>'employees','pk'=>'id','read'=>'employees.read','write'=>'employees.write'],
    'employee_activities'=>['table'=>'employee_activities','pk'=>'id','read'=>'activities.read','write'=>'activities.write'],
    'remuneration_periods'=>['table'=>'remuneration_periods','pk'=>'id','read'=>'remuneration.read','write'=>'remuneration.write'],
    'remuneration_items'=>['table'=>'remuneration_items','pk'=>'id','read'=>'remuneration.read','write'=>'remuneration.write'],
    'users'=>['table'=>'users','pk'=>'id','read'=>'users.read','write'=>'users.write','deny'=>['password_hash']],
    'roles'=>['table'=>'roles','pk'=>'id','read'=>'users.read','write'=>null],
    'permissions'=>['table'=>'permissions','pk'=>'id','read'=>'users.read','write'=>null],
    'role_permissions'=>['table'=>'role_permissions','pk'=>null,'read'=>'users.read','write'=>null],
    'api_logs'=>['table'=>'api_logs','pk'=>'id','read'=>'audit.read','write'=>null],
    'audit_logs'=>['table'=>'audit_logs','pk'=>'id','read'=>'audit.read','write'=>null],
    'settings'=>['table'=>'settings','pk'=>'setting_key','read'=>'settings.read','write'=>'settings.write'],
    'units'=>['table'=>'units','pk'=>'id','read'=>'units.read','write'=>'units.write'],
  ];
}

function bo_table_columns(string $table): array {
  $st=db()->prepare('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_KEY,EXTRA,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
  $st->execute([$table]); $out=[]; foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['COLUMN_NAME']]=$r; return $out;
}
function bo_clean_payload(array $data,array $columns,array $deny=[]): array {
  $out=[]; foreach($data as $k=>$v){ if(isset($columns[$k]) && !in_array($k,$deny,true) && stripos((string)$columns[$k]['EXTRA'],'auto_increment')===false) $out[$k]=$v; } return $out;
}
function bo_audit(array $conn,string $action,string $resource,?string $id,array $payload=[]): void {
  try{ $desc=json_encode(['source'=>'backoffice_api','connection_id'=>(int)($conn['id']??0),'resource'=>$resource,'record_id'=>$id,'payload'=>$payload],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); db()->prepare('INSERT INTO audit_logs(user_id,action,description,created_at) VALUES(NULL,?,?,NOW())')->execute([$action,$desc]); }catch(Throwable $e){}
}
function bo_resource_meta(string $resource): array { $r=bo_resource_registry(); if(!isset($r[$resource])) pairing_err('Resource tidak dikenal: '.$resource,404); return $r[$resource]; }
function bo_parse_filters(array $columns): array {
  $where=[];$params=[];
  foreach($_GET as $k=>$v){ if(in_array($k,['resource','limit','offset','order_by','order_dir','id','include_meta'],true)) continue; if(isset($columns[$k]) && !is_array($v)){ $where[]='`'.$k.'`=?'; $params[]=$v; } }
  return [$where,$params];
}
function bo_mask_row(array $row,array $deny=[]): array { foreach($deny as $d) unset($row[$d]); return $row; }
