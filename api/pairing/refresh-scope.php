<?php
require_once __DIR__ . '/../../core/api_pairing.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') pairing_err('Method tidak diizinkan',405);
$conn=pairing_auth('readonly');
$in=pairing_input();
$desired=trim((string)($in['desired_scope']??''));
if($desired==='') $desired=pairing_scope_for((string)($conn['remote_system_type']??$conn['connection_type']??''),'dapur');
if(!pairing_allowed_scope($desired)) pairing_err('Scope tidak dikenali: '.$desired,422);
db()->prepare("UPDATE api_connections SET access_scope=?, last_test_at=NOW(), last_test_status='ok', last_test_message=?, updated_at=NOW() WHERE id=?")->execute([$desired,'Scope direfresh menjadi '.$desired,(int)$conn['id']]);
pairing_log_event(null,'api/pairing/refresh-scope.php','in','scope_refreshed','Scope direfresh menjadi '.$desired,['connection_id'=>(int)$conn['id']]);
pairing_ok(['message'=>'Scope berhasil direfresh.','access_scope'=>$desired]);
