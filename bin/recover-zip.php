<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$archive=getenv('DAMAGED_ZIP') ?: 'C:/xampp/htdocs/ferry-wp/wp-content/updraft/backup_2026-07-30-1537_Ferrytelecom_7c34160f9982-uploads8.zip';
$extract=in_array('--extract',$argv,true);
$root=dirname(__DIR__).'/storage/private-uploads';
$wanted=[];
foreach(rows("SELECT post_id,meta_value FROM wp_postmeta WHERE meta_key='_wp_attached_file'") as $m) {
    $relative=str_replace('\\','/',ltrim($m['meta_value'],'/\\'));
    if($relative!=='' && !str_contains($relative,'..') && !str_contains($relative,"\0")) $wanted['uploads/'.$relative]=true;
}
$in=fopen($archive,'rb'); if(!$in) throw new RuntimeException('Cannot open archive.');
$size=filesize($archive); $entries=0; $matched=0; $recovered=0; $invalid='';
while(ftell($in)+30<=$size) {
    $start=ftell($in);
    $header=fread($in,30);
    if(strlen($header)<30 || substr($header,0,4)!=="PK\x03\x04") { $invalid='No next local header at '.$start; break; }
    $h=unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength',substr($header,4));
    if(($h['flags'] & 0x08)!==0) { $invalid='Data descriptor at '.$start; break; }
    $name=fread($in,$h['nameLength']);
    if(strlen($name)!==$h['nameLength']) { $invalid='Truncated name'; break; }
    if($h['extraLength']) fseek($in,$h['extraLength'],SEEK_CUR);
    $dataStart=ftell($in); $dataEnd=$dataStart+$h['compressed'];
    if($dataEnd>$size) { $invalid='Truncated data for '.$name; break; }
    $entries++;
    if(isset($wanted[$name])) {
        $matched++;
        $relative=substr($name,strlen('uploads/'));
        $dest=$root.'/'.$relative;
        if($extract && !is_file($dest) && in_array($h['method'],[0,8],true)) {
            $parent=dirname($dest);
            if(!is_dir($parent) && !mkdir($parent,0775,true) && !is_dir($parent)) throw new RuntimeException('Cannot create target directory.');
            $tmp=$dest.'.part'; $out=fopen($tmp,'wb');
            if(!$out) throw new RuntimeException('Cannot write recovered file.');
            $remaining=$h['compressed'];
            if($h['method']===0) {
                stream_copy_to_stream($in,$out,$remaining);
            } else {
                $inflater=inflate_init(ZLIB_ENCODING_RAW);
                while($remaining>0) {
                    $chunk=fread($in,min(65536,$remaining));
                    if($chunk==='') break;
                    $remaining-=strlen($chunk);
                    $decoded=inflate_add($inflater,$chunk,$remaining===0?ZLIB_FINISH:ZLIB_NO_FLUSH);
                    if($decoded===false) break;
                    fwrite($out,$decoded);
                }
            }
            fclose($out);
            $length=filesize($tmp);
            $crc=hexdec(hash_file('crc32b',$tmp));
            if($length===$h['uncompressed'] && $crc===$h['crc'] && rename($tmp,$dest)) $recovered++;
            else { unlink($tmp); echo "FAILED $name\n"; }
        }
    }
    fseek($in,$dataEnd,SEEK_SET);
}
fclose($in);
echo json_encode(['archive'=>basename($archive),'entries_scanned'=>$entries,'originals_in_archive'=>$matched,'recovered'=>$recovered,'stop_reason'=>$invalid],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
