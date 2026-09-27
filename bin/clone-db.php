<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$config = require dirname(__DIR__) . '/config.php';
$source = getenv('SOURCE_DB_NAME') ?: 'ferry-wp';
$target = (string)$config['db_name'];
foreach ([$source,$target] as $name) if (!preg_match('/^[A-Za-z0-9_-]+$/',$name)) throw new RuntimeException('Invalid database name.');
if ($source === $target) throw new RuntimeException('Source and target databases must differ.');
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$config['db_host'],$config['db_port']),$config['db_user'],$config['db_password'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->exec("SET SESSION sql_mode=''"); // Preserve legacy WooCommerce table defaults exactly.
$quote = static fn(string $name): string => '`'.str_replace('`','``',$name).'`';
$sourceQ=$quote($source); $targetQ=$quote($target);
$pdo->exec("CREATE DATABASE IF NOT EXISTS $targetQ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=".$pdo->quote($source)." AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$done=0;
foreach ($tables as $table) {
    $tableQ=$quote($table);
    $sourceCount=(int)$pdo->query("SELECT COUNT(*) FROM $sourceQ.$tableQ")->fetchColumn();
    $exists=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=".$pdo->quote($target)." AND table_name=".$pdo->quote($table))->fetchColumn();
    if ($exists) {
        $targetCount=(int)$pdo->query("SELECT COUNT(*) FROM $targetQ.$tableQ")->fetchColumn();
        if ($targetCount===$sourceCount) { echo "SKIP $table ($sourceCount)\n"; $done++; continue; }
        throw new RuntimeException("Target table $table contains $targetCount rows, source has $sourceCount. Resolve this before resuming.");
    }
    $pdo->exec("CREATE TABLE $targetQ.$tableQ LIKE $sourceQ.$tableQ");
    try {
        if ($sourceCount>0) $pdo->exec("INSERT INTO $targetQ.$tableQ SELECT * FROM $sourceQ.$tableQ");
        $copied=(int)$pdo->query("SELECT COUNT(*) FROM $targetQ.$tableQ")->fetchColumn();
        if ($copied!==$sourceCount) throw new RuntimeException("Row count mismatch for $table");
        echo "COPY $table ($copied)\n";
        $done++;
    } catch (Throwable $e) {
        $pdo->exec("DROP TABLE $targetQ.$tableQ");
        throw $e;
    }
}
echo "COMPLETE: $done tables copied or verified from $source to $target\n";
