<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$source=getenv('SOURCE_DB_NAME') ?: 'ferry-wp';
if(!preg_match('/^[A-Za-z0-9_-]+$/',$source)) throw new RuntimeException('Invalid source database name.');
$sourceQ='`'.str_replace('`','``',$source).'`';
$target=(string)cfg('db_name'); $targetQ='`'.str_replace('`','``',$target).'`';
$tables=rows('SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_type=? ORDER BY table_name',[$source,'BASE TABLE']);
$mismatches=[];
foreach($tables as $table) {
    $name='`'.str_replace('`','``',$table['table_name']).'`';
    $left=(int)db()->query("SELECT COUNT(*) FROM $sourceQ.$name")->fetchColumn();
    $right=(int)db()->query("SELECT COUNT(*) FROM $targetQ.$name")->fetchColumn();
    if($left!==$right) $mismatches[]=['table'=>$table['table_name'],'source'=>$left,'target'=>$right];
}
$types=rows("SELECT post_type,post_status,COUNT(*) n FROM wp_posts WHERE post_type IN ('product','shop_order','page','attachment') GROUP BY post_type,post_status");
$media=rows("SELECT meta_value FROM wp_postmeta WHERE meta_key='_wp_attached_file'");
$wanted=[];
foreach($media as $m) $wanted[str_replace('\\','/',$m['meta_value'])]=true;
$root=dirname(__DIR__).'/storage/private-uploads'; $present=0;
foreach(array_keys($wanted) as $relative) if(is_file($root.'/'.$relative)) $present++;
$thumbnails=rows("SELECT m.meta_value FROM wp_posts p JOIN wp_postmeta thumb ON thumb.post_id=p.ID AND thumb.meta_key='_thumbnail_id' JOIN wp_postmeta m ON m.post_id=CAST(thumb.meta_value AS UNSIGNED) AND m.meta_key='_wp_attached_file' WHERE p.post_type='product' AND p.post_status='publish'");
$thumbnailMissing=0; foreach($thumbnails as $t) if(!is_file($root.'/'.$t['meta_value'])) $thumbnailMissing++;
$report=['source_database'=>$source,'app_database'=>$target,'source_tables'=>count($tables),'table_count_mismatches'=>$mismatches,'users'=>(int)(row('SELECT COUNT(*) n FROM wp_users')['n'] ?? 0),'post_types'=>$types,'media_originals_referenced'=>count($wanted),'media_originals_recovered'=>$present,'media_originals_missing'=>count($wanted)-$present,'published_product_thumbnails'=>count($thumbnails),'published_product_thumbnails_missing'=>$thumbnailMissing];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
