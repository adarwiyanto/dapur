<?php
require_once __DIR__ . '/../../core/backoffice_resources.php';
$in=pairing_input(); $resource=trim((string)($in['resource']??'')); $action=strtolower(trim((string)($in['action']??''))); $meta=bo_resource_meta($resource);
if(empty($meta['write'])) pairing_err('Resource ini hanya dapat dibaca.',403);
$conn=pairing_auth((string)$meta['write']); $cols=bo_table_columns($meta['table']); $pk=$meta['pk']??null; $deny=$meta['deny']??[]; $data=is_array($in['data']??null)?$in['data']:[]; $id=$in['id']??null;
if(($in['dry_run']??false) || (string)($_GET['dry_run']??'')==='1') pairing_ok(['message'=>'Dry-run valid. Tidak ada data yang diubah.','resource'=>$resource,'action'=>$action]);
try{
 db()->beginTransaction();
 if($action==='create'){
   $clean=bo_clean_payload($data,$cols,$deny); if(!$clean) throw new RuntimeException('Data create kosong/tidak valid.');
   $names=array_keys($clean); $sql='INSERT INTO `'.$meta['table'].'` (`'.implode('`,`',$names).'`) VALUES ('.implode(',',array_fill(0,count($names),'?')).')'; db()->prepare($sql)->execute(array_values($clean)); $newId=$pk?db()->lastInsertId():null; bo_audit($conn,'api.create',$resource,$newId?:null,$clean); db()->commit(); pairing_ok(['message'=>'Data berhasil dibuat.','id'=>$newId?:null]);
 }
 if($action==='update'){
   if(!$pk || $id===null || $id==='') throw new RuntimeException('ID wajib untuk update.'); $clean=bo_clean_payload($data,$cols,array_merge($deny,[$pk])); if(!$clean) throw new RuntimeException('Data update kosong/tidak valid.');
   $set=implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($clean))); $vals=array_values($clean); $vals[]=$id; $st=db()->prepare('UPDATE `'.$meta['table'].'` SET '.$set.' WHERE `'.$pk.'`=?'); $st->execute($vals); bo_audit($conn,'api.update',$resource,(string)$id,$clean); db()->commit(); pairing_ok(['message'=>'Data berhasil diperbarui.','affected'=>$st->rowCount()]);
 }
 if(in_array($action,['deactivate','hide','restore','activate'],true)){
   if(!$pk || $id===null || $id==='') throw new RuntimeException('ID wajib.'); $field=isset($cols['is_active'])?'is_active':null; if(!$field) throw new RuntimeException('Resource tidak memiliki status aktif/nonaktif.'); $value=in_array($action,['restore','activate'],true)?1:0; $st=db()->prepare('UPDATE `'.$meta['table'].'` SET `'.$field.'`=? WHERE `'.$pk.'`=?'); $st->execute([$value,$id]); bo_audit($conn,'api.'.$action,$resource,(string)$id,[$field=>$value]); db()->commit(); pairing_ok(['message'=>'Status berhasil diperbarui.','affected'=>$st->rowCount()]);
 }
 if($action==='delete'){
   if(!$pk || $id===null || $id==='') throw new RuntimeException('ID wajib untuk delete.');
   // Hard delete hanya untuk data detail/draft yang eksplisit diminta. Master/transaksi utama wajib memakai deactivate/cancel dari UI bisnis.
   $allow=['purchase_items','bom_items','production_items','stock_opname_items','stock_transfer_items','employee_activities','remuneration_items','product_mappings'];
   if(!in_array($resource,$allow,true)) throw new RuntimeException('Hard delete tidak diizinkan untuk resource ini. Gunakan update/deactivate atau action bisnis khusus.');
   $st=db()->prepare('DELETE FROM `'.$meta['table'].'` WHERE `'.$pk.'`=?'); $st->execute([$id]); bo_audit($conn,'api.delete',$resource,(string)$id,[]); db()->commit(); pairing_ok(['message'=>'Data berhasil dihapus.','affected'=>$st->rowCount()]);
 }
 throw new RuntimeException('Action tidak didukung.');
}catch(Throwable $e){ if(db()->inTransaction()) db()->rollBack(); pairing_err($e->getMessage(),422); }
