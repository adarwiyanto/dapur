<?php
require_once __DIR__ . '/../../core/api_pairing.php';
$conn=pairing_auth('readonly');
pairing_ok(['message'=>'Dapur Back Office API aktif','system'=>'dapur','scope'=>$conn['access_scope'],'time'=>date('Y-m-d H:i:s')]);
