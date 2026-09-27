<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

/** @return array<string,bool> */
function table_indexes(string $table): array {
    $found=[];
    foreach(rows('SHOW INDEX FROM '.$table) as $index)$found[(string)$index['Key_name']]=true;
    return $found;
}

$indexes=table_indexes('wp_postmeta');
$statements=[];
if(!isset($indexes['app_post_meta_key']))$statements[]="ADD INDEX app_post_meta_key (post_id, meta_key(191))";
if(!isset($indexes['app_meta_value_post']))$statements[]="ADD INDEX app_meta_value_post (meta_key(64), meta_value(64), post_id)";

if(!$statements){echo "Performance indexes already installed.\n";exit(0);}
echo "Installing ".count($statements)." performance index(es) on wp_postmeta...\n";
db()->exec('ALTER TABLE wp_postmeta '.implode(', ',$statements));
echo "Database optimization complete.\n";
