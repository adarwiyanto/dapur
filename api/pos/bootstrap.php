<?php
declare(strict_types=1);
require_once __DIR__.'/../../core/pos_api.php';
$session=pos_require_session();
if(!pos_user_can_sell($session)) pos_json(['ok'=>false,'error'=>'Akses POS ditolak.'],403);
$products=[];
foreach(all('SELECT id,code,sku,name,category,unit,sale_price,image_path,updated_at FROM finished_products WHERE is_active=1 ORDER BY category,name') as $p){
  $p['id']=(int)$p['id']; $p['sale_price']=(float)$p['sale_price']; $p['stock_qty']=stock_qty('finished',(int)$p['id']); $products[]=$p;
}
$customers=[];
if(table_exists('customers')) foreach(all('SELECT id,customer_code,customer_name,phone,address,updated_at FROM customers WHERE is_active=1 ORDER BY customer_name') as $c){$c['id']=(int)$c['id'];$customers[]=$c;}
pos_json(['ok'=>true,'server_time'=>date('c'),'products'=>$products,'customers'=>$customers]);
