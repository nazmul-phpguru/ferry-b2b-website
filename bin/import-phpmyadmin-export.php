<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') exit(2);
$file=$argv[1]??'';
if (!is_file($file) || !preg_match('/\.sql(?:\.gz)?$/i',$file)) {
    fwrite(STDERR,"Usage: php import-phpmyadmin-export.php path/to/full-export.sql[.gz]\n"); exit(2);
}
$config=require dirname(__DIR__).'/config.php';
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$config['db_host'],$config['db_port']),$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='ferry_live_snapshot';
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$name'")->fetchColumn();
if ($count) { fwrite(STDERR,"Snapshot database already has $count tables; import stopped to preserve it.\n"); exit(1); }
$mysql='C:/xampp/mysql/bin/mysql.exe';
if (!is_file($mysql)) { fwrite(STDERR,"MySQL client not found.\n"); exit(1); }
$command=[$mysql,'--host='.$config['db_host'],'--port='.(int)$config['db_port'],'--user='.$config['db_user'],'--default-character-set=utf8mb4','--max-allowed-packet=64M',$name];
$environment=getenv();
if ((string)$config['db_password']!=='') $environment['MYSQL_PWD']=(string)$config['db_password'];
$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
if (!is_resource($process)) { fwrite(STDERR,"MySQL client failed to start.\n"); exit(1); }
$gz=str_ends_with(strtolower($file),'.gz');
$input=$gz?gzopen($file,'rb'):fopen($file,'rb');
if ($input===false) { proc_terminate($process); exit(1); }
$bytes=0;
while ($gz?!gzeof($input):!feof($input)) {
    $chunk=$gz?gzgets($input,65536):fgets($input,65536);
    if ($chunk===false) break;
    // Recent MariaDB dumps emit a client sandbox directive that XAMPP's MySQL client cannot parse.
    if (str_contains($chunk,'enable the sandbox mode')) continue;
    $length=strlen($chunk); $offset=0;
    while ($offset<$length) {
        $written=@fwrite($pipes[0],substr($chunk,$offset));
        if ($written===false || $written===0) { fwrite(STDERR,"MySQL client stopped accepting input.\n"); break 2; }
        $offset+=$written;
    }
    $bytes+=$length;
}
$gz?gzclose($input):fclose($input);
fclose($pipes[0]);
$stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$exit=proc_close($process);
if ($exit!==0) { fwrite(STDERR,"Import failed: ".substr(trim($stderr),0,1000)."\n"); exit(1); }
$count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$name'")->fetchColumn();
echo "Imported $count tables ($bytes uncompressed SQL bytes) into $name.\n";
