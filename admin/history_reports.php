<?php
if (!function_exists('dapur_history_context')) {
 function dapur_valid_date(string $value): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
  return $d && $d->format('Y-m-d')===$value;
 }
 function dapur_history_context(string $default='month'): array {
  $allowed=['today','week','month','last_month','two_months','custom'];
  $range=(string)($_GET['range']??$default);
  if(!in_array($range,$allowed,true)) $range=$default;
  $today=new DateTimeImmutable('today');
  switch($range){
   case 'today': $from=$today; $to=$today; break;
   case 'week': $from=$today->modify('monday this week'); $to=$today; break;
   case 'last_month': $from=$today->modify('first day of last month'); $to=$today->modify('last day of last month'); break;
   case 'two_months': $from=$today->modify('first day of last month'); $to=$today; break;
   case 'custom':
    $fromRaw=(string)($_GET['from']??''); $toRaw=(string)($_GET['to']??'');
    $from=dapur_valid_date($fromRaw)?new DateTimeImmutable($fromRaw):$today->modify('first day of this month');
    $to=dapur_valid_date($toRaw)?new DateTimeImmutable($toRaw):$today;
    if($from>$to){ [$from,$to]=[$to,$from]; }
    break;
   case 'month':
   default: $range='month'; $from=$today->modify('first day of this month'); $to=$today; break;
  }
  return [
   'open'=>(string)($_GET['history']??'')==='1',
   'range'=>$range,
   'from'=>$from->format('Y-m-d'),
   'to'=>$to->format('Y-m-d'),
   'detail'=>(int)($_GET['detail']??0),
  ];
 }
 function dapur_history_range_label(array $ctx): string {
  $labels=['today'=>'Hari ini','week'=>'Minggu ini','month'=>'Bulan ini','last_month'=>'Bulan lalu','two_months'=>'Dua bulan terakhir','custom'=>'Custom'];
  return ($labels[$ctx['range']]??'Periode').' · '.date('d/m/Y',strtotime($ctx['from'])).'–'.date('d/m/Y',strtotime($ctx['to']));
 }
 function dapur_history_filter_form(string $page,array $ctx,bool $includeShortcuts=true): string {
  $opts=$includeShortcuts?[
   'today'=>'Hari ini','week'=>'Minggu ini','month'=>'Bulan ini','last_month'=>'Bulan lalu','two_months'=>'Dua bulan terakhir','custom'=>'Custom'
  ]:[
   'month'=>'Bulan ini','last_month'=>'Bulan lalu','two_months'=>'Dua bulan terakhir','custom'=>'Custom'
  ];
  $html='<form method="get" class="history-filter" data-history-filter><input type="hidden" name="page" value="'.e($page).'"><input type="hidden" name="history" value="1"><p><label>Periode<select name="range" data-history-range>';
  foreach($opts as $k=>$v) $html.='<option value="'.e($k).'" '.($ctx['range']===$k?'selected':'').'>'.e($v).'</option>';
  $custom=$ctx['range']==='custom'?'':' hidden';
  $html.='</select></label></p><p data-history-custom'.$custom.'><label>Dari<input type="date" name="from" value="'.e($ctx['from']).'"></label></p><p data-history-custom'.$custom.'><label>Sampai<input type="date" name="to" value="'.e($ctx['to']).'"></label></p><p class="history-filter-action"><button class="btn" type="submit">Terapkan Filter</button></p></form>';
  return $html;
 }
 function dapur_history_detail_url(string $page,array $ctx,int $id): string {
  return '?page='.rawurlencode($page).'&history=1&range='.rawurlencode((string)$ctx['range']).'&from='.rawurlencode((string)$ctx['from']).'&to='.rawurlencode((string)$ctx['to']).'&detail='.$id;
 }
 function dapur_company_contact_line(array $company): string {
  $parts=[];
  foreach(['address','phone','email','extra'] as $k){ $v=trim((string)($company[$k]??'')); if($v!=='') $parts[]=$v; }
  return implode(' • ',$parts);
 }
 function dapur_report_header(string $title,string $number,string $subtitle=''): string {
  $c=company_info(); $contact=dapur_company_contact_line($c);
  return '<div class="record-letterhead"><img src="'.e(company_logo_url()).'" alt="Logo"><div><div class="record-company">'.e($c['name']).'</div>'.(trim((string)$c['branch'])!==''?'<div class="record-branch">'.e($c['branch']).'</div>':'').($contact!==''?'<div class="record-contact">'.e($contact).'</div>':'').'</div></div><div class="record-title-row"><div><h2>'.e($title).'</h2>'.($subtitle!==''?'<div class="muted">'.e($subtitle).'</div>':'').'</div><div class="record-number"><span>Nomor</span><strong>'.e($number).'</strong></div></div>';
 }
 function dapur_record_modal(string $id,string $title,string $body,bool $auto=false): string {
  return '<div class="modal-backdrop record-modal" data-record-modal="'.e($id).'" '.($auto?'data-auto-open="1" ':'').'hidden><section class="modal-card record-modal-card" role="dialog" aria-modal="true" aria-label="'.e($title).'"><div class="modal-head no-print"><div><h3>'.e($title).'</h3><div class="muted small">Detail dokumen siap dicetak.</div></div><button type="button" class="modal-close" data-record-close aria-label="Tutup">&times;</button></div><div class="record-modal-scroll"><article class="record-report" data-print-report="'.e($id).'">'.$body.'</article></div><div class="modal-actions record-actions no-print"><button type="button" class="btn" data-print-target="'.e($id).'">Print / Simpan PDF</button><button type="button" class="btn light" data-record-close>Tutup</button></div></section></div>';
 }
 function dapur_history_modal(string $key,string $title,string $filterHtml,string $tableHtml,bool $auto=false): string {
  return '<div class="modal-backdrop history-modal" data-history-modal="'.e($key).'" '.($auto?'data-auto-open="1" ':'').'hidden><section class="modal-card history-modal-card" role="dialog" aria-modal="true" aria-label="'.e($title).'"><div class="modal-head"><div><h3>'.e($title).'</h3><div class="muted small">Gunakan filter periode, lalu buka detail dokumen.</div></div><button type="button" class="modal-close" data-history-close aria-label="Tutup">&times;</button></div><div class="history-modal-scroll">'.$filterHtml.$tableHtml.'</div></section></div>';
 }
 function dapur_status_label(string $status): string {
  return match($status){
   'posted'=>'Diposting','sent_to_store'=>'Terkirim ke toko','received_by_store'=>'Diterima toko','failed_sync'=>'Gagal sinkron','draft'=>'Draft','cancelled'=>'Dibatalkan','submitted'=>'Diajukan','approved'=>'Disetujui','paid'=>'Dibayar','rejected'=>'Ditolak',default=>$status
  };
 }
}
