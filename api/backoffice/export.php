<?php
require_once __DIR__ . '/../../core/backoffice_resources.php';
$dataset=trim((string)($_GET['dataset']??''));
$map=['products'=>'products','sales'=>'stock_transfers','stock_ledger'=>'stock_ledger','stock_transfers'=>'stock_transfers','employees'=>'employees','bom'=>'boms','production'=>'productions','purchases'=>'purchases','activities'=>'employee_activities','remuneration'=>'remuneration_periods'];
if(!isset($map[$dataset])) pairing_err('Dataset tidak dikenal: '.$dataset,404);
$resource=$map[$dataset]; $meta=bo_resource_meta($resource); pairing_auth((string)$meta['read']);
$cols=bo_table_columns($meta['table']); $limit=max(1,min(500,(int)($_GET['limit']??100))); $pk=$meta['pk']??array_key_first($cols); $sql='SELECT * FROM `'.$meta['table'].'` ORDER BY `'.$pk.'` DESC LIMIT '.$limit; $rows=db()->query($sql)->fetchAll(PDO::FETCH_ASSOC); foreach($rows as &$r) $r=bo_mask_row($r,$meta['deny']??[]);
pairing_ok(['message'=>'OK','dataset'=>$dataset,'data'=>$rows,'count'=>count($rows),'dry_run'=>(string)($_GET['dry_run']??'')==='1']);
