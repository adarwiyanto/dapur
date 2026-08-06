<?php
declare(strict_types=1);

/** Single source of truth for item discount and sale total calculation. */
function sales_money(float $value): float { return round($value, 2); }

function sales_calculate(array $inputRows): array {
    $rows=[]; $grossTotal=0.0; $discountTotal=0.0; $netTotal=0.0;
    foreach($inputRows as $index=>$row){
        $qty=(float)($row['qty']??0); $price=(float)($row['price']??0);
        if($qty<=0) throw new InvalidArgumentException('Qty baris '.($index+1).' harus lebih dari nol.');
        if($price<0) throw new InvalidArgumentException('Harga baris '.($index+1).' tidak boleh negatif.');
        $type=strtolower(trim((string)($row['discount_type']??'none')));
        if(!in_array($type,['none','percent','nominal'],true)) throw new InvalidArgumentException('Jenis diskon baris '.($index+1).' tidak valid.');
        $value=max(0.0,(float)($row['discount_value']??0));
        $gross=sales_money($qty*$price);
        if($type==='percent'){
            if($value>100) throw new InvalidArgumentException('Diskon persen baris '.($index+1).' tidak boleh lebih dari 100%.');
            $discount=sales_money($gross*$value/100);
        }elseif($type==='nominal'){
            $discount=sales_money($value);
        }else{
            $value=0.0; $discount=0.0;
        }
        if($discount>$gross) throw new InvalidArgumentException('Diskon baris '.($index+1).' melebihi subtotal bruto.');
        $net=sales_money($gross-$discount);
        $netUnit=$qty>0?sales_money($net/$qty):0.0;
        $rows[]=array_merge($row,['qty'=>$qty,'price'=>$price,'discount_type'=>$type,'discount_value'=>$value,'gross'=>$gross,'discount_amount'=>$discount,'subtotal'=>$net,'net_unit_price'=>$netUnit]);
        $grossTotal+=$gross; $discountTotal+=$discount; $netTotal+=$net;
    }
    return ['rows'=>$rows,'gross'=>sales_money($grossTotal),'discount'=>sales_money($discountTotal),'total'=>sales_money($netTotal)];
}
