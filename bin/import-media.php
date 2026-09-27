<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
if (!class_exists(ZipArchive::class)) throw new RuntimeException('Enable PHP zip extension for this one-time import.');
require dirname(__DIR__).'/src/bootstrap.php';
$source=getenv('MEDIA_BACKUP_DIR') ?: 'C:/xampp/htdocs/ferry-wp/wp-content/updraft';
$extract=in_array('--extract',$argv,true);
$archives=glob(rtrim($source,'/\\').'/*-uploads*.zip') ?: [];
sort($archives,SORT_STRING);
$wanted=[];
foreach(rows("SELECT post_id,meta_value FROM wp_postmeta WHERE meta_key='_wp_attached_file'") as $m) {
    $relative=str_replace('\\','/',ltrim($m['meta_value'],'/\\'));
    if ($relative==='' || str_contains($relative,'..') || str_contains($relative,"\0")) continue;
    $wanted['uploads/'.$relative]=(int)$m['post_id'];
}
$found=[]; $failed=[]; $copied=0;
$root=dirname(__DIR__).'/storage/private-uploads';
foreach($archives as $archive) {
    $zip=new ZipArchive();
    if($zip->open($archive)!==true) { $failed[]=basename($archive); echo "DAMAGED ".basename($archive)."\n"; continue; }
    $matched=0;
    for($i=0;$i<$zip->numFiles;$i++) {
        $entry=$zip->getNameIndex($i);
        if(!isset($wanted[$entry]) || isset($found[$entry])) continue;
        $found[$entry]=true; $matched++;
        if(!$extract) continue;
        $relative=substr($entry,strlen('uploads/'));
        $dest=$root.'/'.$relative;
        $parent=dirname($dest);
        if(!is_dir($parent) && !mkdir($parent,0775,true) && !is_dir($parent)) throw new RuntimeException('Cannot create media directory.');
        if(is_file($dest)) continue;
        $input=$zip->getStream($entry);
        if(!$input) { unset($found[$entry]); continue; }
        $tmp=$dest.'.part';
        $output=fopen($tmp,'wb');
        if(!$output) { fclose($input); throw new RuntimeException('Cannot write media file.'); }
        stream_copy_to_stream($input,$output);
        fclose($input); fclose($output);
        if(!rename($tmp,$dest)) throw new RuntimeException('Cannot finish media file.');
        $copied++;
    }
    echo basename($archive).": $matched originals".($extract?", $copied extracted total":'')."\n";
    $zip->close();
}
echo json_encode(['mode'=>$extract?'extract':'audit','wanted'=>count($wanted),'found'=>count($found),'missing'=>count($wanted)-count($found),'copied'=>$copied,'damaged_archives'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
