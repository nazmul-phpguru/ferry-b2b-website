<?php
declare(strict_types=1);

function swiss_qr_payload(array $meta,int $orderId): string {
    $currency=strtoupper(trim((string)($meta['_order_currency'] ?? 'CHF')));
    if(!in_array($currency,['CHF','EUR'],true))throw new RuntimeException('Swiss QR payment supports CHF and EUR orders only.');
    $iban=strtoupper(preg_replace('/\s+/','',(string)cfg('swiss_qr_iban','CH960020620684004901M')) ?? '');
    if(!preg_match('/^CH[A-Z0-9]{19}$/',$iban))throw new RuntimeException('The Swiss QR IBAN is not configured correctly.');
    $reordered=substr($iban,4).substr($iban,0,4);$remainder=0;
    foreach(str_split($reordered) as $character){
        $digits=ctype_alpha($character)?(string)(ord($character)-55):$character;
        foreach(str_split($digits) as $digit)$remainder=($remainder*10+(int)$digit)%97;
    }
    if($remainder!==1)throw new RuntimeException('The Swiss QR IBAN failed its checksum.');
    $company=trim((string)($meta['_billing_company'] ?? ''));
    $person=trim((string)($meta['_billing_first_name'] ?? '').' '.(string)($meta['_billing_last_name'] ?? ''));
    $name=mb_substr($company!==''?$company:$person,0,70);
    $streetAddress=trim((string)($meta['_billing_address_1'] ?? ''));
    $street=$streetAddress;$number='';
    if(preg_match('/^(.+?)\s+(\d+[A-Za-z]?(?:[\/-]\d+)?)(?:\s*)$/u',$streetAddress,$match)){$street=$match[1];$number=$match[2];}
    $street=mb_substr($street,0,70);
    $city=mb_substr(trim((string)($meta['_billing_city'] ?? '')),0,35);
    $postcode=trim((string)($meta['_billing_postcode'] ?? ''));
    $country=strtoupper(trim((string)($meta['_billing_country'] ?? 'CH')));
    if(!preg_match('/^[A-Z]{2}$/',$country))$country='CH';
    // A missing or incomplete debtor address is represented by seven empty elements.
    $debtor=$name!=='' && $city!=='' && $postcode!=='' && $street!==''?
        ['S',$name,$street,$number,$postcode,$city,$country]:array_fill(0,7,'');
    $amount=(float)($meta['_order_total'] ?? 0);
    if($amount<=0 || $amount>999999999.99)throw new RuntimeException('The order amount cannot be used for a Swiss QR payment.');
    $lines=array_merge(
        ['SPC','0200','1',$iban,'S','Ferry Telecom AG','Industriestrasse','8','6203','Sempach Station','CH'],
        array_fill(0,7,''),
        [number_format($amount,2,'.',''),$currency],
        $debtor,
        ['NON','','Order '.$orderId,'EPD']
    );
    return implode("\n",$lines);
}

function swiss_qr_rs_multiply(int $x,int $y): int {
    $z=0;
    for($i=7;$i>=0;$i--){
        $z=($z<<1)^(($z>>7)*0x11D);
        $z^=(($y>>$i)&1)*$x;
    }
    return $z;
}

function swiss_qr_rs_remainder(array $data,int $degree): array {
    $generator=array_fill(0,$degree,0);$generator[$degree-1]=1;$root=1;
    for($i=0;$i<$degree;$i++){
        for($j=0;$j<$degree;$j++){
            $generator[$j]=swiss_qr_rs_multiply($generator[$j],$root);
            if($j+1<$degree)$generator[$j]^=$generator[$j+1];
        }
        $root=swiss_qr_rs_multiply($root,0x02);
    }
    $result=array_fill(0,$degree,0);
    foreach($data as $byte){
        $factor=$byte^$result[0];array_shift($result);$result[]=0;
        foreach($generator as $j=>$value)$result[$j]^=swiss_qr_rs_multiply($value,$factor);
    }
    return $result;
}

function swiss_qr_bits(int $value,int $count): array {
    $bits=[];for($i=$count-1;$i>=0;$i--)$bits[]=($value>>$i)&1;
    return $bits;
}

function swiss_qr_set(array &$matrix,array &$reserved,int $x,int $y,bool $black): void {
    if($x<0 || $y<0 || $y>=count($matrix) || $x>=count($matrix))return;
    $matrix[$y][$x]=$black;$reserved[$y][$x]=true;
}

function swiss_qr_matrix(string $payload): array {
    // QR Model 2, byte mode, error correction M. The supported versions cover
    // the structured payment payload; the smallest fitting version is chosen.
    $specs=[
        9=>[182,22,3,36,2,37,[6,26,46]],
        10=>[216,26,4,43,1,44,[6,28,50]],
        11=>[254,30,1,50,4,51,[6,30,54]],
        12=>[290,22,6,36,2,37,[6,32,58]],
        13=>[334,22,8,37,1,38,[6,34,62]],
        14=>[365,24,4,40,5,41,[6,26,46,66]],
        15=>[415,24,5,41,5,42,[6,26,48,70]],
    ];
    $version=0;$spec=[];
    foreach($specs as $candidate=>$candidateSpec){
        $needed=4+8+4+16+strlen($payload)*8;
        if($needed<=$candidateSpec[0]*8){$version=$candidate;$spec=$candidateSpec;break;}
    }
    if($version===0)throw new RuntimeException('Swiss QR payment data is too long.');
    [$capacity,$eccLength,$group1Count,$group1Size,$group2Count,$group2Size,$centers]=$spec;
    $bits=array_merge(swiss_qr_bits(7,4),swiss_qr_bits(26,8),swiss_qr_bits(4,4),swiss_qr_bits(strlen($payload),16));
    foreach(unpack('C*',$payload) as $byte)$bits=array_merge($bits,swiss_qr_bits($byte,8));
    $remaining=$capacity*8-count($bits);
    for($i=0;$i<min(4,$remaining);$i++)$bits[]=0;
    while(count($bits)%8!==0)$bits[]=0;
    $data=[];foreach(array_chunk($bits,8) as $chunk){$byte=0;foreach($chunk as $bit)$byte=($byte<<1)|$bit;$data[]=$byte;}
    $pad=0;while(count($data)<$capacity){$data[]=$pad++%2===0?0xEC:0x11;}
    $blocks=[];$offset=0;
    foreach([[$group1Count,$group1Size],[$group2Count,$group2Size]] as [$count,$size]){
        for($i=0;$i<$count;$i++){
            $part=array_slice($data,$offset,$size);$offset+=$size;
            $blocks[]=['data'=>$part,'ecc'=>swiss_qr_rs_remainder($part,$eccLength)];
        }
    }
    $codewords=[];
    for($i=0;$i<$group2Size;$i++)foreach($blocks as $block)if(isset($block['data'][$i]))$codewords[]=$block['data'][$i];
    for($i=0;$i<$eccLength;$i++)foreach($blocks as $block)$codewords[]=$block['ecc'][$i];
    $size=$version*4+17;
    $matrix=array_fill(0,$size,array_fill(0,$size,false));
    $reserved=array_fill(0,$size,array_fill(0,$size,false));
    foreach([[3,3],[$size-4,3],[3,$size-4]] as [$cx,$cy]){
        for($dy=-4;$dy<=4;$dy++)for($dx=-4;$dx<=4;$dx++){
            $distance=max(abs($dx),abs($dy));
            swiss_qr_set($matrix,$reserved,$cx+$dx,$cy+$dy,$distance!==2 && $distance!==4);
        }
    }
    for($i=8;$i<$size-8;$i++){
        swiss_qr_set($matrix,$reserved,6,$i,$i%2===0);
        swiss_qr_set($matrix,$reserved,$i,6,$i%2===0);
    }
    foreach($centers as $cy)foreach($centers as $cx){
        if(($cx===6 && ($cy===6 || $cy===$centers[count($centers)-1])) || ($cy===6 && $cx===$centers[count($centers)-1]))continue;
        for($dy=-2;$dy<=2;$dy++)for($dx=-2;$dx<=2;$dx++)swiss_qr_set($matrix,$reserved,$cx+$dx,$cy+$dy,max(abs($dx),abs($dy))!==1);
    }
    // Reserve the two format strips and the version information modules.
    for($i=0;$i<9;$i++){
        if($i!==6){swiss_qr_set($matrix,$reserved,8,$i,false);swiss_qr_set($matrix,$reserved,$i,8,false);}
    }
    for($i=0;$i<8;$i++){
        swiss_qr_set($matrix,$reserved,$size-1-$i,8,false);
        swiss_qr_set($matrix,$reserved,8,$size-1-$i,false);
    }
    swiss_qr_set($matrix,$reserved,8,$size-8,true);
    if($version>=7)for($i=0;$i<18;$i++){
        $a=$size-11+$i%3;$b=intdiv($i,3);
        swiss_qr_set($matrix,$reserved,$a,$b,false);
        swiss_qr_set($matrix,$reserved,$b,$a,false);
    }
    $dataBits=[];foreach($codewords as $word)$dataBits=array_merge($dataBits,swiss_qr_bits($word,8));
    $index=0;$up=true;
    for($right=$size-1;$right>=1;$right-=2){
        if($right===6)$right=5;
        for($vertical=0;$vertical<$size;$vertical++){
            $y=$up?$size-1-$vertical:$vertical;
            for($delta=0;$delta<2;$delta++){
                $x=$right-$delta;
                if($reserved[$y][$x])continue;
                $matrix[$y][$x]=($dataBits[$index++] ?? 0)===1;
            }
        }
        $up=!$up;
    }
    // Mask 0 is valid for every QR version. Format data encodes EC level M.
    for($y=0;$y<$size;$y++)for($x=0;$x<$size;$x++)if(!$reserved[$y][$x] && (($x+$y)%2===0))$matrix[$y][$x]=!$matrix[$y][$x];
    $formatData=0; // M = binary 00, mask pattern 000
    $rem=$formatData;
    for($i=0;$i<10;$i++)$rem=($rem<<1)^(($rem>>9)*0x537);
    $format=(($formatData<<10)|$rem)^0x5412;
    for($i=0;$i<=5;$i++)swiss_qr_set($matrix,$reserved,8,$i,(($format>>$i)&1)===1);
    swiss_qr_set($matrix,$reserved,8,7,(($format>>6)&1)===1);
    swiss_qr_set($matrix,$reserved,8,8,(($format>>7)&1)===1);
    swiss_qr_set($matrix,$reserved,7,8,(($format>>8)&1)===1);
    for($i=9;$i<15;$i++)swiss_qr_set($matrix,$reserved,14-$i,8,(($format>>$i)&1)===1);
    for($i=0;$i<8;$i++)swiss_qr_set($matrix,$reserved,$size-1-$i,8,(($format>>$i)&1)===1);
    for($i=8;$i<15;$i++)swiss_qr_set($matrix,$reserved,8,$size-15+$i,(($format>>$i)&1)===1);
    swiss_qr_set($matrix,$reserved,8,$size-8,true);
    if($version>=7){
        $rem=$version;
        for($i=0;$i<12;$i++)$rem=($rem<<1)^(($rem>>11)*0x1F25);
        $versionBits=($version<<12)|$rem;
        for($i=0;$i<18;$i++){
            $bit=(($versionBits>>$i)&1)===1;$a=$size-11+$i%3;$b=intdiv($i,3);
            swiss_qr_set($matrix,$reserved,$a,$b,$bit);
            swiss_qr_set($matrix,$reserved,$b,$a,$bit);
        }
    }
    return $matrix;
}
