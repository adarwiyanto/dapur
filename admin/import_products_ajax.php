<?php
declare(strict_types=1);
require_once __DIR__.'/../core/auth.php';
require_login();
verify_csrf();
require_perm('products');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function import_json(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function import_api_log(?int $storeId, string $endpoint, string $status, string $message, $payload = null, string $direction = 'out'): void {
    try {
        if (!function_exists('table_exists') || !table_exists('api_logs')) return;
        $json = $payload === null ? null : (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        execq('INSERT INTO api_logs(store_id,endpoint,direction,status,message,payload_json) VALUES(?,?,?,?,?,?)', [$storeId, $endpoint, $direction, substr($status, 0, 40), $message, $json]);
    } catch (Throwable $e) {}
}
function import_fail(int $code, array $data, ?int $storeId = null, string $endpoint = '', string $status = 'import_failed', $payload = null): never {
    import_api_log($storeId, $endpoint, $status, (string)($data['message'] ?? 'Import gagal'), $payload, 'out');
    import_json($data, $code);
}
function import_curl_call(string $base, string $path, string $token = '', array $payload = [], string $method = 'GET'): array {
    $base = rtrim(trim($base), '/');
    if ($base === '') return [0, '', 'Base URL kosong'];
    if (!function_exists('curl_init')) return [0, '', 'Ekstensi PHP cURL belum aktif'];
    $url = $base . '/' . ltrim($path, '/');
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_HTTPHEADER=>$headers, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3, CURLOPT_NOSIGNAL=>1]);
    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, (string)$body, (string)$err];
}
function import_pick_items(array $json): array {
    if (isset($json['items']) && is_array($json['items'])) $items = $json['items'];
    elseif (isset($json['products']) && is_array($json['products'])) $items = $json['products'];
    elseif (isset($json['data']['products']) && is_array($json['data']['products'])) $items = $json['data']['products'];
    elseif (isset($json['data']) && is_array($json['data'])) $items = $json['data'];
    elseif ((function_exists('array_is_list') ? array_is_list($json) : array_keys($json) === range(0, count($json)-1))) $items = $json;
    else $items = [];
    return array_values(array_filter($items, 'is_array'));
}
function import_clean_string($value): string { return trim((string)($value ?? '')); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') import_json(['ok'=>false,'message'=>'Method tidak valid.'],405);
if (($_POST['act'] ?? '') !== 'import') import_json(['ok'=>false,'message'=>'Aksi import tidak valid.'],400);

$u = current_user();
$sourceType = strtolower(trim((string)($_POST['source_type'] ?? 'store')));
$endpoint = 'api/v1/kitchen/products.php';
$sourceLabel = 'Toko/API';
$isHopeSource = false;
$logStoreId = null;
$sourceStoreId = null;
$connection = null;

if ($sourceType === 'hope') {
    $connectionId = (int)($_POST['connection_id'] ?? 0);
    $connection = one("SELECT * FROM api_connections WHERE id=? AND status='active' AND (remote_system_type='hope' OR connection_type='hope') LIMIT 1", [$connectionId]);
    if (!$connection) import_json(['ok'=>false,'message'=>'Koneksi HOPe/HP tidak ditemukan atau belum aktif.'],404);
    $token = (string)($connection['token_plain'] ?? ($connection['access_token_plain'] ?? ''));
    if ($token === '') import_json(['ok'=>false,'message'=>'Token HOPe/HP kosong. Cek status pairing di API & Integrasi.'],422);
    $sourceLabel = 'HOPe/HP';
    $isHopeSource = true;
    [$httpCode, $body, $curlError] = import_curl_call((string)$connection['remote_base_url'], $endpoint, $token, [], 'GET');
} else {
    $storeId = (int)($_POST['store_id'] ?? 0);
    $store = one("SELECT * FROM stores WHERE id=? AND is_active=1 AND NOT (store_code LIKE 'HOPE-%' OR COALESCE(notes,'') LIKE '%HOPe%')", [$storeId]);
    if (!$store) import_json(['ok'=>false,'message'=>'Toko/API tidak ditemukan atau tidak aktif.'],404);
    $logStoreId = (int)$store['id'];
    $sourceStoreId = (int)$store['id'];
    [$httpCode, $body, $curlError] = import_curl_call((string)$store['api_base_url'], $endpoint, (string)($store['api_token'] ?? ''), [], 'GET');
}

if ($curlError !== '') {
    import_fail(502, ['ok'=>false,'message'=>'Gagal menghubungi API '.$sourceLabel.': '.$curlError,'http_code'=>$httpCode], $logStoreId, $endpoint, 'import_failed', ['source_type'=>$sourceType,'http_code'=>$httpCode,'curl_error'=>$curlError]);
}
if ($httpCode < 200 || $httpCode >= 300) {
    $preview = trim(substr($body, 0, 240));
    $message = 'API '.$sourceLabel.' memberi HTTP '.$httpCode.($preview!==''?' - '.$preview:'');
    if ($httpCode === 401) $message = 'Token API '.$sourceLabel.' tidak valid. Cek token/pairing aktif. Detail: HTTP 401'.($preview!==''?' - '.$preview:'');
    if ($httpCode === 403) $message = 'Token API '.$sourceLabel.' aktif tetapi permission ditolak. Pastikan scope mengizinkan products.read. Detail: HTTP 403'.($preview!==''?' - '.$preview:'');
    if ($httpCode === 404) $message = 'Endpoint API '.$sourceLabel.' tidak ditemukan: '.$endpoint.'. Detail: HTTP 404'.($preview!==''?' - '.$preview:'');
    import_fail(502, ['ok'=>false,'message'=>$message,'endpoint'=>$endpoint,'http_code'=>$httpCode], $logStoreId, $endpoint, 'import_failed', ['source_type'=>$sourceType,'http_code'=>$httpCode,'response_preview'=>substr($body,0,1200)]);
}
$json = json_decode($body, true);
if (!is_array($json)) import_fail(502, ['ok'=>false,'message'=>'Response API '.$sourceLabel.' bukan JSON valid: '.json_last_error_msg(),'http_code'=>$httpCode], $logStoreId, $endpoint, 'invalid_json', ['source_type'=>$sourceType,'response_preview'=>substr($body,0,1200)]);

$items = import_pick_items($json);
if ($isHopeSource) {
    $items = array_values(array_filter($items, static function($it): bool {
        $type = strtolower(trim((string)($it['product_type'] ?? $it['item_type'] ?? '')));
        return $type === 'finished_good' || $type === 'finished' || $type === 'barang_jadi';
    }));
}
if (!$items) {
    $preview = trim(substr($body, 0, 240));
    import_fail(422, ['ok'=>false,'message'=>'Response API valid dari endpoint '.$endpoint.', tetapi daftar produk jadi kosong/tidak dikenali.'.($preview!==''?' Preview: '.$preview:''),'http_code'=>$httpCode], $logStoreId, $endpoint, 'import_failed', ['source_type'=>$sourceType,'http_code'=>$httpCode,'response_preview'=>$preview]);
}

$inserted=0; $updated=0; $skipped=0; $errors=[]; $total=count($items);
try {
    db()->beginTransaction();
    foreach ($items as $idx => $it) {
        $pid = import_clean_string($it['id'] ?? $it['product_id'] ?? $it['productId'] ?? '');
        $name = import_clean_string($it['name'] ?? $it['product_name'] ?? $it['productName'] ?? '');
        if ($pid === '' || $name === '') { $skipped++; if(count($errors)<5) $errors[]='Item #'.($idx+1).' dilewati: id/nama produk kosong.'; continue; }
        $code = import_clean_string($it['code'] ?? $it['sku'] ?? '');
        $sku = import_clean_string($it['sku'] ?? $it['code'] ?? '');
        $category = import_clean_string($it['category'] ?? $it['category_name'] ?? '');
        $unit = import_clean_string($it['base_unit'] ?? $it['sale_unit'] ?? $it['unit'] ?? 'pack') ?: 'pack';
        $imagePath = import_clean_string($it['image_path'] ?? $it['image'] ?? $it['photo'] ?? '');
        $sourceProductId = $isHopeSource ? ('HOPE:' . (int)($connection['id'] ?? 0) . ':' . $pid) : $pid;
        if ($isHopeSource) {
            $it['_source_system'] = 'hope';
            $it['_source_connection_id'] = (int)($connection['id'] ?? 0);
            $it['_source_connection_name'] = (string)($connection['connection_name'] ?? 'HOPe/HP');
        }
        $payloadJson = json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $existing = $isHopeSource
            ? one('SELECT id FROM finished_products WHERE source_store_id IS NULL AND source_product_id=? LIMIT 1', [$sourceProductId])
            : one('SELECT id FROM finished_products WHERE source_store_id=? AND source_product_id=? LIMIT 1', [$sourceStoreId, $sourceProductId]);
        if ($existing) {
            execq('UPDATE finished_products SET code=?, sku=?, name=?, category=?, unit=?, image_path=?, source_payload_json=?, is_active=1, updated_at=NOW() WHERE id=?', [$code,$sku,$name,$category,$unit,$imagePath,$payloadJson,(int)$existing['id']]);
            $finishedId=(int)$existing['id']; $updated++;
        } else {
            execq('INSERT INTO finished_products(code,sku,name,category,unit,sale_price,transfer_price,image_path,source_store_id,source_product_id,source_payload_json,is_active,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())', [$code,$sku,$name,$category,$unit,0,0,$imagePath,$sourceStoreId,$sourceProductId,$payloadJson,1]);
            $finishedId=(int)db()->lastInsertId(); $inserted++;
        }
        if (!$isHopeSource && $sourceStoreId) {
            execq('INSERT INTO finished_product_store_mappings(finished_product_id,store_id,store_product_id,store_sku,store_product_name,store_price,is_active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE store_product_id=VALUES(store_product_id),store_sku=VALUES(store_sku),store_product_name=VALUES(store_product_name),is_active=1', [$finishedId,$sourceStoreId,$pid,$sku,$name,0]);
        }
    }
    $message='Import via API '.$sourceLabel.'. Total response: '.$total.', baru: '.$inserted.', update: '.$updated.', dilewati: '.$skipped;
    execq('INSERT INTO product_import_logs(store_id,total_imported,message,created_by) VALUES(?,?,?,?)', [$logStoreId,$inserted+$updated,$message,(int)($u['id']??0)]);
    import_api_log($logStoreId,$endpoint,'import_ok',$message,['source_type'=>$sourceType,'total'=>$total,'inserted'=>$inserted,'updated'=>$updated,'skipped'=>$skipped],'out');
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    import_fail(500, ['ok'=>false,'message'=>'Import gagal saat simpan DB: '.$e->getMessage(),'http_code'=>$httpCode], $logStoreId, $endpoint, 'import_failed', ['source_type'=>$sourceType,'exception'=>$e->getMessage()]);
}
import_json(['ok'=>true,'message'=>'Import produk selesai: '.($inserted+$updated).' item. Baru: '.$inserted.', update: '.$updated.', dilewati: '.$skipped.'.','http_code'=>$httpCode,'total'=>$total,'inserted'=>$inserted,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors,'source_type'=>$sourceType]);
