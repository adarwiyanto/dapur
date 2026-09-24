<?php
declare(strict_types=1);
require_once __DIR__.'/../../core/pos_api.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') pos_json(['ok'=>false,'error'=>'Method tidak diizinkan.'],405);
$session=pos_require_session(); if(!pos_user_can_sell($session)) pos_json(['ok'=>false,'error'=>'Akses POS ditolak.'],403);
$in=pos_input(); $uuid=trim((string)($in['uuid']??'')); $deviceId=trim((string)($in['device_id']??''));
if(!preg_match('/^[a-f0-9-]{32,40}$/i',$uuid)) pos_json(['ok'=>false,'error'=>'UUID transaksi tidak valid.'],422);
if($deviceId==='' || $deviceId!==(string)$session['device_id']) pos_json(['ok'=>false,'error'=>'Device tidak sesuai dengan sesi.'],403);
$existing=one('SELECT id,sale_no,total_amount FROM kitchen_sales_headers WHERE pos_uuid=? LIMIT 1',[$uuid]);
if($existing) pos_json(['ok'=>true,'duplicate'=>true,'sale_id'=>(int)$existing['id'],'sale_no'=>(string)$existing['sale_no'],'total_amount'=>(float)$existing['total_amount']]);
$items=$in['items']??[]; if(!is_array($items)||count($items)<1) pos_json(['ok'=>false,'error'=>'Transaksi tidak memiliki item.'],422); if(count($items)>200) pos_json(['ok'=>false,'error'=>'Item transaksi terlalu banyak.'],422);
$calcInput=[]; $itemMap=[];
foreach($items as $idx=>$row){
  if(!is_array($row)) pos_json(['ok'=>false,'error'=>'Item transaksi tidak valid.'],422);
  $pid=(int)($row['product_id']??0); $p=one('SELECT id,name,unit,is_active FROM finished_products WHERE id=? LIMIT 1',[$pid]);
  if(!$p || (int)$p['is_active']!==1) pos_json(['ok'=>false,'error'=>'Produk pada baris '.($idx+1).' tidak aktif/tidak ditemukan.'],422);
  $price=(float)($row['original_price']??0); if($price<0) pos_json(['ok'=>false,'error'=>'Harga produk tidak valid.'],422);
  $entry=['item'=>['id'=>$pid,'type'=>'finished','name'=>(string)$p['name'],'unit'=>(string)$p['unit']],'qty'=>(float)($row['qty']??0),'price'=>$price,'discount_type'=>(string)($row['discount_type']??'none'),'discount_value'=>(float)($row['discount_value']??0)];
  $calcInput[]=$entry; $itemMap[]=$p;
}
try{$calc=sales_calculate($calcInput);}catch(Throwable $e){pos_json(['ok'=>false,'error'=>$e->getMessage()],422);}
$clientTotal=(float)($in['total_amount']??$calc['total']); if(abs($clientTotal-$calc['total'])>0.01) pos_json(['ok'=>false,'error'=>'Total transaksi tidak konsisten. Silakan sinkron ulang data.'],422);
$customerId=!empty($in['customer_id'])?(int)$in['customer_id']:null; if($customerId && (!table_exists('customers')||!one('SELECT id FROM customers WHERE id=? AND is_active=1',[$customerId]))) $customerId=null;
$payment=(string)($in['payment_method']??'cash'); if(!in_array($payment,['cash','qris','transfer','other'],true))$payment='other';
$paid=max(0,(float)($in['paid_amount']??0)); $change=max(0,(float)($in['change_amount']??0)); $saleDate=(string)($in['sale_date']??date('Y-m-d')); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$saleDate))$saleDate=date('Y-m-d');
$notes=trim((string)($in['notes']??'')); $saleNo=pos_new_sale_no(); $uid=(int)$session['user_id']; $clientCreatedRaw=(string)($in['created_at']??''); $clientTs=$clientCreatedRaw!==''?strtotime($clientCreatedRaw):false; $clientCreated=$clientTs!==false?date('Y-m-d H:i:s',$clientTs):null;
try{
  db()->beginTransaction();
  execq('INSERT INTO kitchen_sales_headers(sale_no,sale_date,store_id,customer_id,sale_type,status,total_amount,notes,created_by,posted_at,pos_uuid,pos_device_id,payment_method,paid_amount,change_amount,pos_created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?)',[
    $saleNo,$saleDate,null,$customerId,'direct','posted',$calc['total'],$notes!==''?$notes:null,$uid,$uuid,$deviceId,$payment,$paid,$change,$clientCreated
  ]);
  $sid=(int)db()->lastInsertId();
  foreach($calc['rows'] as $r){$it=$r['item'];execq('INSERT INTO kitchen_sales_items(sale_id,item_type,item_ref_id,item_name,finished_product_id,qty,unit,transfer_price,original_price,discount_type,discount_value,discount_amount,subtotal) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[
    $sid,'finished',(int)$it['id'],(string)$it['name'],(int)$it['id'],$r['qty'],(string)$it['unit'],$r['net_unit_price'],$r['price'],$r['discount_type'],$r['discount_value'],$r['discount_amount'],$r['subtotal']
  ]);add_ledger('finished',(int)$it['id'],'direct_sale','kitchen_sales_headers',$sid,0,(float)$r['qty'],(float)$r['net_unit_price'],$saleNo,$uid);}
  db()->commit();
  pos_json(['ok'=>true,'sale_id'=>$sid,'sale_no'=>$saleNo,'total_amount'=>$calc['total']]);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack(); if(str_contains(strtolower($e->getMessage()),'duplicate')){$ex=one('SELECT id,sale_no,total_amount FROM kitchen_sales_headers WHERE pos_uuid=?',[$uuid]);if($ex)pos_json(['ok'=>true,'duplicate'=>true,'sale_id'=>(int)$ex['id'],'sale_no'=>(string)$ex['sale_no'],'total_amount'=>(float)$ex['total_amount']]);}pos_json(['ok'=>false,'error'=>'Gagal menyimpan transaksi POS.'],500);}
