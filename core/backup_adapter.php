<?php
require_once __DIR__.'/helpers.php';
function dapur_backup_service() {
    static $service = null;
    if ($service !== null) return $service;
    if (!class_exists('GoogleDriveBackupService', false)) require_once __DIR__.'/backup_google.php';
    $cfg = app_config();
    $rootPath=dirname(__DIR__);
    $homePrivate='';
    $normalized=str_replace('\\','/',$rootPath);
    if(preg_match('~^/home/([^/]+)/public_html(?:/.*)?$~',$normalized,$m)) $homePrivate='/home/'.$m[1].'/private_uploads/dapur';
    $external=array();
    foreach(array('images','docs','documents','uploads') as $label){ $p=$homePrivate!==''?$homePrivate.'/'.$label:''; if($p!=='' && is_dir($p)) $external[$label]=$p; }

    $getter = function ($key, $default = null) { return setting($key, $default); };
    $setter = function ($key, $value) { set_setting($key, $value); };
    $service = new GoogleDriveBackupService(array(
        'pdo'=>db(), 'db'=>$cfg['db'], 'app_key'=>'DAPUR',
        'app_name'=>isset($cfg['app']['name']) ? $cfg['app']['name'] : 'Dapur Adena',
        'root_path'=>$rootPath, 'private_path'=>$rootPath.'/storage/private_backup',
        'external_paths'=>$external,
        'jobs_table'=>'backup_jobs',
        'timezone'=>isset($cfg['app']['timezone']) ? $cfg['app']['timezone'] : 'Asia/Jakarta',
        'get_setting'=>$getter, 'set_setting'=>$setter
    ));
    return $service;
}
