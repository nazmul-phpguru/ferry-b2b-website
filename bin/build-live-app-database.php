<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') exit(2);
$prefix=$argv[1]??'';
if (!preg_match('/^[A-Za-z0-9_]+$/',$prefix) || strlen($prefix)<3) {
    fwrite(STDERR,"Usage: php build-live-app-database.php WORDPRESS_TABLE_PREFIX\n"); exit(2);
}
$config=require dirname(__DIR__).'/config.php';
$source='ferry_live_snapshot'; $target='ferry_app_live';
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$config['db_host'],$config['db_port']),$config['db_user'],$config['db_password'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->exec("SET SESSION sql_mode=''");
$quote=static fn(string $name): string=>'`'.str_replace('`','``',$name).'`';
$tables=$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema='.$pdo->quote($source)." AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
if (!$tables) { fwrite(STDERR,"Snapshot database is empty.\n"); exit(1); }
$matching=array_values(array_filter($tables,static fn(string $name): bool=>str_starts_with($name,$prefix)));
if (count($matching)<20) { fwrite(STDERR,"Only ".count($matching)." tables match prefix $prefix; stopped.\n"); exit(1); }
$pdo->exec('CREATE DATABASE IF NOT EXISTS '.$quote($target).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$count=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='.$pdo->quote($target))->fetchColumn();
if ($count) { fwrite(STDERR,"Target database already has $count tables; stopped to preserve it.\n"); exit(1); }
$copied=0;
foreach ($matching as $sourceTable) {
    $destination='wp_'.substr($sourceTable,strlen($prefix));
    $from=$quote($source).'.'.$quote($sourceTable);
    $to=$quote($target).'.'.$quote($destination);
    $pdo->exec("CREATE TABLE $to LIKE $from");
    $pdo->exec("INSERT INTO $to SELECT * FROM $from");
    $sourceCount=(int)$pdo->query("SELECT COUNT(*) FROM $from")->fetchColumn();
    $targetCount=(int)$pdo->query("SELECT COUNT(*) FROM $to")->fetchColumn();
    if ($sourceCount!==$targetCount) throw new RuntimeException("Row count mismatch in $sourceTable");
    $copied++;
    echo "$destination: $targetCount\n";
}
$pdo->prepare("UPDATE `$target`.`wp_usermeta` SET meta_key=CONCAT('wp_',SUBSTRING(meta_key,LENGTH(?) + 1)) WHERE LEFT(meta_key,LENGTH(?))=?")
    ->execute([$prefix,$prefix,$prefix]);
$pdo->prepare("UPDATE `$target`.`wp_options` SET option_name=CONCAT('wp_',SUBSTRING(option_name,LENGTH(?) + 1)) WHERE LEFT(option_name,LENGTH(?))=?")
    ->execute([$prefix,$prefix,$prefix]);
echo "Copied $copied tables to $target with wp_ names. Point config.php at this database, then run bin/init-app.php.\n";
