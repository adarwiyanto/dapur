<?php
require_once __DIR__ . '/../../core/backoffice_resources.php';
$resource=trim((string)($_GET['resource']??'')); $meta=bo_resource_meta($resource); $conn=pairing_auth((string)$meta['read']);
$cols=bo_table_columns($meta['table']); if(!$cols) pairing_err('Tabel resource tidak tersedia.',404);
$pk=$meta['pk']??null; $deny=$meta['deny']??[]; $id=$_GET['id']??null;
$limit=max(1,min(500,(int)($_GET['limit']??100))); $offset=max(0,(int)($_GET['offset']??0));
$orderBy=(string)($_GET['order_by']??($pk?:array_key_first($cols))); if(!isset($cols[$orderBy])) $orderBy=$pk?:array_key_first($cols);
$orderDir=strtoupper((string)($_GET['order_dir']??'DESC')); if(!in_array($orderDir,['ASC','DESC'],true)) $orderDir='DESC';
[$where,$params]=bo_parse_filters($cols); if($id!==null && $pk){ $where[]='`'.$pk.'`=?'; $params[]=$id; }
$sql='SELECT * FROM `'.$meta['table'].'`'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY `'.$orderBy.'` '.$orderDir.' LIMIT '.$limit.' OFFSET '.$offset;
$st=db()->prepare($sql); $st->execute($params); $rows=array_map(fn($x)=>bo_mask_row($x,$deny),$st->fetchAll(PDO::FETCH_ASSOC));
$countSql='SELECT COUNT(*) FROM `'.$meta['table'].'`'.($where?' WHERE '.implode(' AND ',$where):''); $ct=db()->prepare($countSql); $ct->execute($params); $total=(int)$ct->fetchColumn();
pairing_ok(['resource'=>$resource,'data'=>$rows,'pagination'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset],'meta'=>($_GET['include_meta']??'0')==='1'?['columns'=>array_keys($cols),'primary_key'=>$pk,'writable'=>!empty($meta['write'])]:null]);
