<?php
declare(strict_types=1);
require_once __DIR__.'/../core/auth.php';
require_login();
verify_csrf();
require_perm('products');

header('Content-Type: application/json; charset=utf-8');

function import_json(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function store_api_call_for_import(array $store, string $path, array $payload = [], string $method = 'GET'): array {
    $base = trim((string)($store['api_base_url'] ?? ''));
    if ($base === '') {
        return [0, '', 'Base URL API toko kosong'];
    }
    if (!function_exists('curl_init')) {
        return [0, '', 'Ekstensi PHP cURL belum aktif'];
    }
    $url = rtrim($base, '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if (!empty($store['api_token'])) {
        $headers[] = 'Authorization: Bearer ' . $store['api_token'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $body, $err];
}

function import_pick_items(array $json): array {
    $items = [];
    if (isset($json['products']) && is_array($json['products'])) {
        $items = $json['products'];
    } elseif (isset($json['data']) && is_array($json['data'])) {
        if (isset($json['data']['products']) && is_array($json['data']['products'])) {
            $items = $json['data']['products'];
        } else {
            $items = $json['data'];
        }
    } elseif (function_exists('array_is_list') ? array_is_list($json) : array_keys($json) === range(0, count($json) - 1)) {
        $items = $json;
    }
    return array_values(array_filter($items, 'is_array'));
}

function import_clean_string($value): string {
    return trim((string)($value ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    import_json(['ok' => false, 'message' => 'Method tidak valid.'], 405);
}
if (($_POST['act'] ?? '') !== 'import') {
    import_json(['ok' => false, 'message' => 'Aksi import tidak valid.'], 400);
}

$u = current_user();
$storeId = (int)($_POST['store_id'] ?? 0);
$store = one('SELECT * FROM stores WHERE id=? AND is_active=1', [$storeId]);
if (!$store) {
    import_json(['ok' => false, 'message' => 'Toko/API tidak ditemukan atau tidak aktif.'], 404);
}

$endpoint = 'api/dapur/products.php';
[$httpCode, $body, $curlError] = store_api_call_for_import($store, $endpoint, [], 'GET');
if ($curlError !== '') {
    import_json(['ok' => false, 'message' => 'Gagal menghubungi API toko: ' . $curlError, 'http_code' => $httpCode], 502);
}
if ($httpCode < 200 || $httpCode >= 300) {
    $preview = trim(substr((string)$body, 0, 240));
    $message = 'API toko memberi HTTP ' . $httpCode . ($preview !== '' ? ' - ' . $preview : '');
    if ($httpCode === 401) {
        $message = 'Token API toko tidak valid. Pastikan token dari menu Admin → API Dapur di toko sudah ditempel di Dapur → Toko & API. Detail: HTTP 401' . ($preview !== '' ? ' - ' . $preview : '');
    } elseif ($httpCode === 403) {
        $message = 'Token API toko aktif tetapi permission ditolak. Pastikan permission API Dapur di toko mengizinkan export produk. Detail: HTTP 403' . ($preview !== '' ? ' - ' . $preview : '');
    } elseif ($httpCode === 404) {
        $message = 'Endpoint API Dapur di toko tidak ditemukan: ' . $endpoint . '. Pastikan patch API Dapur di toko sudah terpasang. Detail: HTTP 404' . ($preview !== '' ? ' - ' . $preview : '');
    }
    import_json(['ok' => false, 'message' => $message, 'endpoint' => $endpoint, 'http_code' => $httpCode], 502);
}

$json = json_decode((string)$body, true);
if (!is_array($json)) {
    import_json(['ok' => false, 'message' => 'Response API toko bukan JSON valid: ' . json_last_error_msg(), 'http_code' => $httpCode], 502);
}

$items = import_pick_items($json);
if (!$items) {
    $preview = trim(substr((string)$body, 0, 240));
    import_json(['ok' => false, 'message' => 'Response API valid dari endpoint ' . $endpoint . ', tetapi daftar produk kosong/tidak dikenali.' . ($preview !== '' ? ' Preview: ' . $preview : ''), 'http_code' => $httpCode], 422);
}

$inserted = 0;
$updated = 0;
$skipped = 0;
$errors = [];
$total = count($items);

try {
    db()->beginTransaction();
    foreach ($items as $idx => $it) {
        $pid = import_clean_string($it['id'] ?? $it['product_id'] ?? $it['productId'] ?? '');
        $name = import_clean_string($it['name'] ?? $it['product_name'] ?? $it['productName'] ?? '');
        if ($pid === '' || $name === '') {
            $skipped++;
            if (count($errors) < 5) {
                $errors[] = 'Item #' . ($idx + 1) . ' dilewati: id/nama produk kosong.';
            }
            continue;
        }

        $code = import_clean_string($it['code'] ?? $it['sku'] ?? '');
        $sku = import_clean_string($it['sku'] ?? $it['code'] ?? '');
        $category = import_clean_string($it['category'] ?? $it['category_name'] ?? '');
        $unit = import_clean_string($it['base_unit'] ?? $it['unit'] ?? 'pack') ?: 'pack';
        // Harga jual toko sengaja tidak diimpor. Harga Jual Dapur / Harga Transfer ke Toko diatur manual di Dapur.
        $salePrice = 0.0;
        $transferPrice = 0.0;
        $imagePath = import_clean_string($it['image_path'] ?? $it['image'] ?? $it['photo'] ?? '');
        $payloadJson = json_encode($it, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = one('SELECT id FROM finished_products WHERE source_store_id=? AND source_product_id=? LIMIT 1', [(int)$store['id'], $pid]);
        if ($existing) {
            execq('UPDATE finished_products SET code=?, sku=?, name=?, category=?, unit=?, image_path=?, source_payload_json=?, is_active=1, updated_at=NOW() WHERE id=?', [$code, $sku, $name, $category, $unit, $imagePath, $payloadJson, (int)$existing['id']]);
            $finishedId = (int)$existing['id'];
            $updated++;
        } else {
            execq('INSERT INTO finished_products(code,sku,name,category,unit,sale_price,transfer_price,image_path,source_store_id,source_product_id,source_payload_json,is_active,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())', [$code, $sku, $name, $category, $unit, $salePrice, $transferPrice, $imagePath, (int)$store['id'], $pid, $payloadJson, 1]);
            $finishedId = (int)db()->lastInsertId();
            $inserted++;
        }

        execq('INSERT INTO finished_product_store_mappings(finished_product_id,store_id,store_product_id,store_sku,store_product_name,store_price,is_active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE store_product_id=VALUES(store_product_id),store_sku=VALUES(store_sku),store_product_name=VALUES(store_product_name),is_active=1', [$finishedId, (int)$store['id'], $pid, $sku, $name, 0]);
    }

    $message = 'Import via API. Total response: ' . $total . ', baru: ' . $inserted . ', update: ' . $updated . ', dilewati: ' . $skipped;
    execq('INSERT INTO product_import_logs(store_id,total_imported,message,created_by) VALUES(?,?,?,?)', [(int)$store['id'], $inserted + $updated, $message, (int)($u['id'] ?? 0)]);
    if (table_exists('api_logs')) {
        execq('INSERT INTO api_logs(store_id,endpoint,direction,status,message,payload_json) VALUES(?,?,?,?,?,?)', [(int)$store['id'], $endpoint, 'in', 'import_ok', $message, json_encode(['total' => $total, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE)]);
    }
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    import_json(['ok' => false, 'message' => 'Import gagal saat simpan DB: ' . $e->getMessage(), 'http_code' => $httpCode], 500);
}

import_json([
    'ok' => true,
    'message' => 'Import produk selesai: ' . ($inserted + $updated) . ' item. Baru: ' . $inserted . ', update: ' . $updated . ', dilewati: ' . $skipped . '.',
    'http_code' => $httpCode,
    'total' => $total,
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped,
    'errors' => $errors,
]);
