<?php
require_once __DIR__ . '/../../core/api_pairing.php';
pairing_auth('readonly');
$data=['finished_products'=>0,'raw_materials'=>0,'pending_pairing'=>pairing_pending_count()];
try{$data['finished_products']=(int)db()->query('SELECT COUNT(*) FROM finished_products WHERE is_active=1')->fetchColumn();}catch(Throwable $e){}
try{$data['raw_materials']=(int)db()->query('SELECT COUNT(*) FROM raw_materials WHERE is_active=1')->fetchColumn();}catch(Throwable $e){}
pairing_ok(['data'=>$data]);
