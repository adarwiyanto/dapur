<?php require_once __DIR__.'/../../core/api.php'; api_auth('products.view'); $rows=all('SELECT * FROM finished_products WHERE is_active=1 ORDER BY name'); api_json(['ok'=>true,'products'=>$rows]);
