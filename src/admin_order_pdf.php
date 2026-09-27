<?php
declare(strict_types=1);

// Small PDF writer for the two order documents. All content comes from the local database.
function order_pdf_escape(string $value): string {
    $value=preg_replace('/\s+/u',' ',trim($value)) ?? '';
    $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value);
    return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$encoded===false?'':$encoded);
}

function order_pdf_text(array &$draw,float $x,float $y,string $value,float $size=9,bool $bold=false): void {
    $draw[]='BT /'.($bold?'F2':'F1').' '.sprintf('%.2F',$size).' Tf '.sprintf('%.2F %.2F',$x,$y).' Td ('.order_pdf_escape($value).') Tj ET';
}

function order_pdf_line(array &$draw,float $x1,float $y1,float $x2,float $y2,float $width=.5): void {
    $draw[]=sprintf('%.2F w %.2F %.2F m %.2F %.2F l S',$width,$x1,$y1,$x2,$y2);
}

function order_pdf_rect(array &$draw,float $x,float $y,float $width,float $height,bool $black=false): void {
    $draw[]=($black?'0 0 0 rg ':'1 1 1 rg ').sprintf('%.2F %.2F %.2F %.2F re f',$x,$y,$width,$height);
}

function order_pdf_width(string $value,float $size): float {
    return strlen((string)iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value))*$size*.48;
}

function order_pdf_right(array &$draw,float $right,float $y,string $value,float $size=9,bool $bold=false): void {
    order_pdf_text($draw,$right-order_pdf_width($value,$size),$y,$value,$size,$bold);
}

function order_pdf_wrap(string $value,int $max): array {
    $value=trim(preg_replace('/\s+/u',' ',$value) ?? '');
    if($value==='')return [''];
    return explode("\n",wordwrap($value,$max,"\n",true));
}

function order_pdf_multiline(array &$draw,float $x,float $y,array $lines,float $size=9,float $leading=13,bool $bold=false): float {
    foreach($lines as $line){order_pdf_text($draw,$x,$y,(string)$line,$size,$bold);$y-=$leading;}
    return $y;
}

function order_pdf_money(float $value,string $currency): string {
    return $currency.' '.number_format($value,2,'.',',');
}

function order_pdf_address(array $meta,string $kind): array {
    $prefix='_'.$kind.'_';
    $lines=[];
    foreach([
        trim((string)($meta[$prefix.'company'] ?? '')),
        trim((string)($meta[$prefix.'first_name'] ?? '').' '.(string)($meta[$prefix.'last_name'] ?? '')),
        trim((string)($meta[$prefix.'address_1'] ?? '')),
        trim((string)($meta[$prefix.'address_2'] ?? '')),
        trim((string)($meta[$prefix.'postcode'] ?? '').' '.(string)($meta[$prefix.'city'] ?? '')),
        trim((string)($meta[$prefix.'country'] ?? '')),
    ] as $line)if($line!=='')$lines[]=$line==='CH'?'Switzerland':$line;
    return $lines;
}

function order_pdf_issuer(array &$draw): void {
    $lines=['ferrytelecom.com','Ferry Telecom AG','Industriestrasse 8',
        '6203 Sempach Station','Switzerland','info@ferrytelecom.com',
        'VAT CHE-254.271.185 MWST','+41 78 204 56 55'];
    foreach($lines as $index=>$line)order_pdf_text($draw,346,808-$index*14,$line,9,$index===1);
}

function order_pdf_terms(array &$draw,float $top): void {
    $top=min($top,185);
    order_pdf_text($draw,57,$top,'Payment Terms',9);
    order_pdf_line($draw,57,$top-4,538,$top-4,.45);
    $left=['For Payments in CHF:','Account name: Ferry Telecom AG','Account number: 0206 00840049.01M','Bank name: UBS Switzerland AG (Switzerland) Ltd.','Sort Code: 0020','IBAN: CH96 0020 6206 8400 4901 M','BIC/Swift: UBSWCHZH80A','Currency: CHF'];
    $right=['For Payments in EURO:','Account name: Ferry Telecom AG','Account number: 0206 00840049.60L','Bank name: UBS Switzerland AG (Switzerland) Ltd.','Sort Code: 0020','IBAN: CH97 0020 6206 8400 4960 L','BIC/Swift: UBSWCHZH80A','Currency: EURO'];
    for($i=0;$i<count($left);$i++){
        $y=$top-21-$i*13;
        order_pdf_text($draw,57,$y,$left[$i],8.3,$i===0);
        order_pdf_text($draw,298,$y,$right[$i],8.3,$i===0);
    }
}

function order_pdf_rows(array $items): array {
    $products=[];
    foreach($items as $item) {
        if(($item['order_item_type'] ?? '')!=='line_item')continue;
        $productId=(int)($item['product_id'] ?? 0);
        $details=$productId>0?rows("SELECT meta_key,meta_value FROM wp_postmeta WHERE post_id=? AND meta_key IN ('_sku','_hs_code','hs_code','_customs_hs_code','_warehouse_location')",[$productId]):[];
        $productMeta=[];foreach($details as $detail)$productMeta[$detail['meta_key']]=$detail['meta_value'];
        $qty=max(1,(float)($item['qty'] ?? 1));
        $products[]=[
            'sku'=>(string)($productMeta['_sku'] ?? ''),
            'hs'=>(string)($productMeta['_hs_code'] ?? $productMeta['hs_code'] ?? $productMeta['_customs_hs_code'] ?? ''),
            'name'=>(string)$item['order_item_name'],
            'qty'=>$qty,
            'total'=>(float)($item['total'] ?? 0),
            'location'=>(string)($productMeta['_warehouse_location'] ?? ''),
        ];
    }
    return $products;
}

function order_pdf_invoice_page(array $order,array $meta,array $items,int &$shown): array {
    $draw=[];$currency=trim((string)($meta['_order_currency'] ?? 'CHF')) ?: 'CHF';
    order_pdf_issuer($draw);
    order_pdf_text($draw,57,660,'INVOICE',17,true);
    $bill=order_pdf_address($meta,'billing');
    order_pdf_multiline($draw,57,632,$bill,9,14);
    $taxId=trim((string)($meta['_billing_vat'] ?? ''));
    $leftY=632-count($bill)*14-12;
    order_pdf_text($draw,57,$leftY,'EORI Number',9);
    order_pdf_text($draw,57,$leftY-14,'VAT Number',9);
    if($taxId!=='')order_pdf_text($draw,57,$leftY-28,$taxId,9);
    $date=strtotime((string)$order['post_date']) ?: time();
    $invoiceNo=trim((string)($meta['_wcpdf_invoice_number'] ?? '')) ?: (string)$order['ID'];
    $details=[
        ['Invoice Number:',$invoiceNo],
        ['Order Number:',(string)$order['ID']],
        ['Order Date:',date('d-M-Y',$date)],
        ['Payment Method:',(string)($meta['_payment_method_title'] ?? '')],
    ];
    $detailY=632;
    foreach($details as [$label,$value]) {
        order_pdf_text($draw,347,$detailY,$label,9);
        $split=order_pdf_wrap($value,27);
        order_pdf_multiline($draw,425,$detailY,$split,9,14);
        $detailY-=max(1,count($split))*14;
    }
    $tableY=505;
    order_pdf_rect($draw,57,$tableY-2,481,21,true);
    foreach([[60,'SKU'],[108,'HS CODE'],[223,'Product'],[349,'Quantity'],[396,'Price'],[444,'Tax rate'],[512,'Total']] as [$x,$label]) {
        $draw[]='1 1 1 rg';
        order_pdf_text($draw,(float)$x,$tableY+5,$label,8.1,true);
    }
    $draw[]='0 0 0 rg';
    $y=$tableY-19;$subtotal=0;
    $allItemsSubtotal=array_sum(array_column($items,'total'));
    $displayTaxRate=$allItemsSubtotal>0?number_format((float)($meta['_order_tax'] ?? 0)/($allItemsSubtotal+(float)($meta['_order_shipping'] ?? 0))*100,1,',',' ').' %':'—';
    $shown=0;
    foreach($items as $item) {
        if($y<298)break;
        $nameLines=order_pdf_wrap($item['name'],23);
        $height=max(35,count($nameLines)*13+9);
        $qty=(float)$item['qty'];$total=(float)$item['total'];$subtotal+=$total;
        order_pdf_text($draw,60,$y,$item['sku'],8.3);
        order_pdf_text($draw,108,$y,$item['hs'],8.3);
        order_pdf_multiline($draw,223,$y,$nameLines,8.5,13);
        order_pdf_text($draw,349,$y,(string)(int)$qty,8.5);
        order_pdf_right($draw,434,$y,order_pdf_money($total/$qty,$currency),8.3);
        order_pdf_text($draw,444,$y,$displayTaxRate,8.3);
        order_pdf_right($draw,535,$y,order_pdf_money($total,$currency),8.3);
        $y-=$height;
        order_pdf_line($draw,57,$y+8,538,$y+8,.35);
        $shown++;
    }
    if($shown<count($items)){order_pdf_terms($draw,185);return $draw;}
    $shipping=(float)($meta['_order_shipping'] ?? 0);
    $tax=(float)($meta['_order_tax'] ?? 0);
    $total=(float)($meta['_order_total'] ?? ($subtotal+$shipping+$tax));
    $summaryY=$y-7;
    foreach([
        ['Subtotal Excl. Tax',$subtotal],
        ['Shipping Costs',$shipping],
        ['Total ex. VAT ('.$displayTaxRate.')',$subtotal+$shipping],
        ['UN81 Value added tax '.$displayTaxRate,$tax],
        ['Total',$total],
    ] as $index=>[$label,$amount]) {
        $rowY=$summaryY-$index*22;
        order_pdf_line($draw,346,$rowY+13,538,$rowY+13,$index===4?1:.35);
        order_pdf_text($draw,349,$rowY,(string)$label,8.2,true);
        order_pdf_right($draw,535,$rowY,order_pdf_money((float)$amount,$currency),8.2,$index===4);
        if($index===4)order_pdf_line($draw,346,$rowY-4,538,$rowY-4,1.4);
    }
    order_pdf_terms($draw,$summaryY-126);
    return $draw;
}

function order_pdf_packing_page(array $order,array $meta,array $items,int &$shown): array {
    $draw=[];order_pdf_issuer($draw);
    order_pdf_text($draw,57,660,'PACKING SLIP',17,true);
    order_pdf_multiline($draw,57,632,order_pdf_address($meta,'shipping'),9,14);
    $date=strtotime((string)$order['post_date']) ?: time();
    foreach([
        ['Order Number:',(string)$order['ID']],
        ['Order Date:',date('d-M-Y',$date)],
        ['Shipping Method:',(string)($meta['_shipping_method_title'] ?? '')],
    ] as $index=>[$label,$value]) {
        $y=632-$index*18;
        order_pdf_text($draw,347,$y,$label,9);
        order_pdf_multiline($draw,426,$y,order_pdf_wrap($value,24),9,14);
    }
    $tableY=529;
    order_pdf_rect($draw,57,$tableY-2,481,21,true);
    foreach([[60,'SKU'],[108,'Product'],[299,'Quantity'],[348,'Location']] as [$x,$label]) {
        $draw[]='1 1 1 rg';order_pdf_text($draw,(float)$x,$tableY+5,$label,8.5,true);
    }
    $draw[]='0 0 0 rg';
    $y=$tableY-20;
    $shown=0;
    foreach($items as $item) {
        if($y<215)break;
        $lines=order_pdf_wrap($item['name'],41);$height=max(34,count($lines)*13+7);
        order_pdf_text($draw,60,$y,$item['sku'],8.5);
        order_pdf_multiline($draw,108,$y,$lines,8.5,13);
        order_pdf_text($draw,299,$y,(string)(int)$item['qty'],8.5);
        order_pdf_text($draw,348,$y,$item['location'],8.5);
        $y-=$height;order_pdf_line($draw,57,$y+8,538,$y+8,.35);
        $shown++;
    }
    order_pdf_terms($draw,185);
    return $draw;
}

function order_pdf_continuation(array $items,array $meta,bool $invoice,bool $last): array {
    $draw=[];order_pdf_issuer($draw);
    order_pdf_text($draw,57,661,$invoice?'INVOICE — CONTINUED':'PACKING SLIP — CONTINUED',15,true);
    $tableY=620;order_pdf_rect($draw,57,$tableY-2,481,21,true);
    $columns=$invoice?[[60,'SKU'],[108,'HS CODE'],[223,'Product'],[349,'Quantity'],[396,'Price'],[444,'Tax rate'],[512,'Total']]:[[60,'SKU'],[108,'Product'],[299,'Quantity'],[348,'Location']];
    foreach($columns as [$x,$label]){$draw[]='1 1 1 rg';order_pdf_text($draw,(float)$x,$tableY+5,$label,8.1,true);}
    $draw[]='0 0 0 rg';$y=$tableY-20;$currency=trim((string)($meta['_order_currency'] ?? 'CHF')) ?: 'CHF';
    $fullSubtotal=(float)($meta['_order_total'] ?? 0)-(float)($meta['_order_shipping'] ?? 0)-(float)($meta['_order_tax'] ?? 0);
    $taxRate=$fullSubtotal>0?number_format((float)($meta['_order_tax'] ?? 0)/($fullSubtotal+(float)($meta['_order_shipping'] ?? 0))*100,1,',',' ').' %':'—';
    foreach($items as $item){
        $nameLines=order_pdf_wrap($item['name'],$invoice?23:41);$height=max(35,count($nameLines)*13+9);
        order_pdf_text($draw,60,$y,$item['sku'],8.3);
        if($invoice){
            order_pdf_text($draw,108,$y,$item['hs'],8.3);
            order_pdf_multiline($draw,223,$y,$nameLines,8.5,13);
            order_pdf_text($draw,349,$y,(string)(int)$item['qty'],8.5);
            order_pdf_right($draw,434,$y,order_pdf_money((float)$item['total']/max(1,(float)$item['qty']),$currency),8.3);
            order_pdf_text($draw,444,$y,$taxRate,8.3);
            order_pdf_right($draw,535,$y,order_pdf_money((float)$item['total'],$currency),8.3);
        }else{
            order_pdf_multiline($draw,108,$y,$nameLines,8.5,13);
            order_pdf_text($draw,299,$y,(string)(int)$item['qty'],8.5);
            order_pdf_text($draw,348,$y,$item['location'],8.5);
        }
        $y-=$height;order_pdf_line($draw,57,$y+8,538,$y+8,.35);
    }
    if($invoice && $last){
        $shipping=(float)($meta['_order_shipping'] ?? 0);$tax=(float)($meta['_order_tax'] ?? 0);
        $entries=[['Subtotal Excl. Tax',$fullSubtotal],['Shipping Costs',$shipping],['Tax '.$taxRate,$tax],['Total',(float)($meta['_order_total'] ?? 0)]];
        foreach($entries as $index=>[$label,$amount]){
            $lineY=$y-12-$index*22;
            order_pdf_line($draw,346,$lineY+13,538,$lineY+13,$index===3?1:.35);
            order_pdf_text($draw,349,$lineY,(string)$label,8.2,true);
            order_pdf_right($draw,535,$lineY,order_pdf_money((float)$amount,$currency),8.2,$index===3);
            if($index===3)order_pdf_line($draw,346,$lineY-4,538,$lineY-4,1.4);
        }
    }
    order_pdf_terms($draw,185);
    return $draw;
}

function order_pdf_swiss_payment_page(array $meta,int $orderId): array {
    $payload=swiss_qr_payload($meta,$orderId);
    $matrix=swiss_qr_matrix($payload);
    $draw=[];$currency=strtoupper((string)($meta['_order_currency'] ?? 'CHF'));
    $iban=preg_replace('/\s+/','',(string)cfg('swiss_qr_iban','CH960020620684004901M')) ?? '';
    $ibanDisplay=implode(' ',str_split($iban,4));
    $amount=number_format((float)($meta['_order_total'] ?? 0),2,'.',',');
    $payable=order_pdf_address($meta,'billing');
    // A4 payment part: receipt 62 x 105 mm, payment section 148 x 105 mm.
    order_pdf_text($draw,57,807,'3 days',9);
    order_pdf_line($draw,0,382,595,382,.6);
    order_pdf_text($draw,263,389,'Separate before paying in',6.2);
    order_pdf_line($draw,176,84,176,382,.6);
    order_pdf_text($draw,14,359,'Receipt',11,true);
    order_pdf_text($draw,190,359,'Payment part',11,true);
    order_pdf_text($draw,14,341,'Account / Payable to',6.4,true);
    order_pdf_multiline($draw,14,331,[$ibanDisplay,'Ferry Telecom AG','Industriestrasse 8','6203 Sempach Station'],7,8.8);
    order_pdf_text($draw,14,277,'Payable by',6.4,true);
    order_pdf_multiline($draw,14,267,array_slice($payable,0,4),7,8.8);
    order_pdf_text($draw,14,174,'Currency',6.4,true);
    order_pdf_text($draw,55,174,'Amount',6.4,true);
    order_pdf_text($draw,14,164,$currency,7.5);
    order_pdf_text($draw,55,164,$amount,7.5);
    order_pdf_text($draw,111,138,'Acceptance point',6.4,true);
    order_pdf_text($draw,335,357,'Account / Payable to',8.5,true);
    order_pdf_multiline($draw,335,344,[$ibanDisplay,'Ferry Telecom AG','Industriestrasse 8','6203 Sempach Station'],8.7,11);
    order_pdf_text($draw,335,284,'Additional information',8.5,true);
    order_pdf_text($draw,335,272,'Order #'.$orderId,8.7);
    order_pdf_text($draw,335,249,'Payable by',8.5,true);
    order_pdf_multiline($draw,335,237,array_slice($payable,0,4),8.7,11);
    order_pdf_text($draw,190,170,'Currency',8.5,true);
    order_pdf_text($draw,241,170,'Amount',8.5,true);
    order_pdf_text($draw,190,158,$currency,9.4);
    order_pdf_text($draw,241,158,$amount,9.4);
    // 46 mm vector QR with a 7 mm Swiss cross, matching the print specification.
    $qrX=190.5;$qrY=202.0;$qrSize=130.4;$module=$qrSize/count($matrix);
    foreach($matrix as $rowIndex=>$row)foreach($row as $columnIndex=>$black)if($black){
        order_pdf_rect($draw,$qrX+$columnIndex*$module,$qrY+($qrSize-$rowIndex*$module)-$module,$module+.015,$module+.015,true);
    }
    $centerX=$qrX+$qrSize/2;$centerY=$qrY+$qrSize/2;
    order_pdf_rect($draw,$centerX-10,$centerY-10,20,20,false);
    order_pdf_rect($draw,$centerX-8,$centerY-8,16,16,true);
    order_pdf_rect($draw,$centerX-2,$centerY-6,4,12,false);
    order_pdf_rect($draw,$centerX-6,$centerY-2,12,4,false);
    return $draw;
}

function order_pdf_assemble(array $pages): string {
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    $refs=[];$number=5;
    foreach($pages as $commands){
        $page=$number++;$stream=$number++;$refs[]=$page.' 0 R';
        $objects[$page]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$stream.' 0 R >>';
        $body=implode("\n",$commands)."\n";
        $objects[$stream]='<< /Length '.strlen($body).' >>'."\nstream\n".$body.'endstream';
    }
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$refs).'] /Count '.count($refs).' >>';
    ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0=>0];
    foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}
    $xref=strlen($pdf);$count=max(array_keys($objects))+1;
    $pdf.="xref\n0 ".$count."\n0000000000 65535 f \n";
    for($i=1;$i<$count;$i++)$pdf.=sprintf('%010d 00000 n ',(int)($offsets[$i] ?? 0))."\n";
    return $pdf."trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

function download_order_document(): never {
    $id=(int)($_GET['id'] ?? 0);$type=(string)($_GET['type'] ?? '');
    if($id<1 || !in_array($type,['invoice','packing-slip'],true)){http_response_code(400);exit('Invalid document request.');}
    $order=row("SELECT ID,post_date FROM wp_posts WHERE ID=? AND post_type='shop_order' LIMIT 1",[$id]);
    if(!$order){http_response_code(404);exit('Order not found.');}
    $metaRows=rows("SELECT meta_key,meta_value FROM wp_postmeta WHERE post_id=? AND meta_key IN ('_wcpdf_invoice_number','_order_total','_order_currency','_order_shipping','_order_tax','_payment_method_title','_billing_first_name','_billing_last_name','_billing_company','_billing_address_1','_billing_address_2','_billing_city','_billing_postcode','_billing_country','_billing_vat','_shipping_first_name','_shipping_last_name','_shipping_company','_shipping_address_1','_shipping_address_2','_shipping_city','_shipping_postcode','_shipping_country') ORDER BY meta_id",[$id]);
    $meta=[];foreach($metaRows as $item)$meta[$item['meta_key']]=$item['meta_value'];
    $shipping=rows("SELECT order_item_name FROM wp_woocommerce_order_items WHERE order_id=? AND order_item_type='shipping' LIMIT 1",[$id]);
    $meta['_shipping_method_title']=(string)($shipping[0]['order_item_name'] ?? '');
    $items=rows("SELECT oi.order_item_name,oi.order_item_type,MAX(CASE WHEN im.meta_key='_qty' THEN im.meta_value END) qty,MAX(CASE WHEN im.meta_key='_line_total' THEN im.meta_value END) total,MAX(CASE WHEN im.meta_key='_product_id' THEN im.meta_value END) product_id FROM wp_woocommerce_order_items oi LEFT JOIN wp_woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id WHERE oi.order_id=? GROUP BY oi.order_item_id ORDER BY oi.order_item_id",[$id]);
    $products=order_pdf_rows($items);
    $invoice=$type==='invoice';
    $shown=0;
    $pages=[$invoice?order_pdf_invoice_page($order,$meta,$products,$shown):order_pdf_packing_page($order,$meta,$products,$shown)];
    $remaining=array_slice($products,$shown);
    while($remaining){
        $chunk=[];$space=600;
        while($remaining){
            $next=$remaining[0];
            $height=max(35,count(order_pdf_wrap((string)$next['name'],$invoice?23:41))*13+9);
            if($chunk && $space-$height<($invoice?330:210))break;
            $chunk[]=array_shift($remaining);$space-=$height;
        }
        $pages[]=order_pdf_continuation($chunk,$meta,$invoice,$remaining===[]);
    }
    if($invoice && in_array(strtoupper((string)($meta['_order_currency'] ?? 'CHF')),['CHF','EUR'],true))$pages[]=order_pdf_swiss_payment_page($meta,$id);
    $pdf=order_pdf_assemble($pages);
    set_wp_meta($id,$invoice?'_ferry_invoice_pdf_downloaded':'_ferry_packing_pdf_downloaded',gmdate('Y-m-d H:i:s'));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.($invoice?'invoice':'packing-slip').'-'.$id.'.pdf"');
    header('Content-Length: '.strlen($pdf));header('X-Content-Type-Options: nosniff');
    echo $pdf;exit;
}
