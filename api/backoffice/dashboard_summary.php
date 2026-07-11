<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
$today=date('Y-m-d'); $month=date('Y-m'); $start=$month.'-01'; $end=date('Y-m-t',strtotime($start));
$data=['system_name'=>'Dapur Adena','finished_products'=>0,'active_finished_products'=>0,'raw_materials'=>0,'pending_pairing'=>pairing_pending_count(),'productions_today'=>0,'pending_distributions'=>0,'employees_count'=>0,'dapur_omset_month'=>0,'omset_dapur_bulan_ini'=>0,'kitchen_revenue_month'=>0,'kitchen_revenue_branches'=>[]];
try{$data['finished_products']=(int)db()->query('SELECT COUNT(*) FROM finished_products')->fetchColumn();}catch(Throwable $e){}
try{$data['active_finished_products']=(int)db()->query('SELECT COUNT(*) FROM finished_products WHERE is_active=1')->fetchColumn();}catch(Throwable $e){}
try{$data['raw_materials']=(int)db()->query('SELECT COUNT(*) FROM raw_materials WHERE is_active=1')->fetchColumn();}catch(Throwable $e){}
try{$st=db()->prepare("SELECT COUNT(*) FROM production_headers WHERE production_date=? AND status<>'cancelled'"); $st->execute([$today]); $data['productions_today']=(int)$st->fetchColumn();}catch(Throwable $e){}
try{$data['pending_distributions']=(int)db()->query("SELECT COUNT(*) FROM kitchen_sales_headers WHERE status='sent_to_store'")->fetchColumn();}catch(Throwable $e){}
try{$data['employees_count']=(int)db()->query('SELECT COUNT(*) FROM employees WHERE is_active=1')->fetchColumn();}catch(Throwable $e){}
try{$st=db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM kitchen_sales_headers WHERE sale_date BETWEEN ? AND ? AND status<>'cancelled'"); $st->execute([$start,$end]); $rev=(float)$st->fetchColumn(); $data['dapur_omset_month']=$rev; $data['omset_dapur_bulan_ini']=$rev; $data['kitchen_revenue_month']=$rev;}catch(Throwable $e){}
try{$st=db()->prepare("SELECT COALESCE(s.store_name,'Tanpa Toko') store_name, COALESCE(SUM(h.total_amount),0) total_amount FROM kitchen_sales_headers h LEFT JOIN stores s ON s.id=h.store_id WHERE h.sale_date BETWEEN ? AND ? AND h.status<>'cancelled' GROUP BY h.store_id,s.store_name ORDER BY total_amount DESC"); $st->execute([$start,$end]); foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $data['kitchen_revenue_branches'][]=['name'=>(string)$r['store_name'],'store_name'=>(string)$r['store_name'],'dapur_omset_month'=>(float)$r['total_amount'],'kitchen_revenue_month'=>(float)$r['total_amount']]; }}catch(Throwable $e){}
pairing_ok(['data'=>$data]);
