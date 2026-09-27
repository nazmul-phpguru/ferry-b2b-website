<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Copy config.example.php to config.php and configure the database.');
}
$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('Invalid configuration.');
}
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

function cfg(string $key, mixed $default = null): mixed { global $config; return $config[$key] ?? $default; }
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('db_host'), cfg('db_port', 3306), cfg('db_name'));
        $pdo = new PDO($dsn, (string)cfg('db_user'), (string)cfg('db_password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function rows(string $sql, array $params = []): array { $q=db()->prepare($sql); $q->execute($params); return $q->fetchAll(); }
function row(string $sql, array $params = []): ?array { $q=db()->prepare($sql); $q->execute($params); return $q->fetch() ?: null; }
function exec_sql(string $sql, array $params = []): int { $q=db()->prepare($sql); $q->execute($params); return $q->rowCount(); }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function path(string $route = '/'): string { return rtrim((string)cfg('base_path', ''), '/') . $route; }
function redirect(string $route): never { header('Location: ' . path($route), true, 303); exit; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function verify_csrf(): void { if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Session expired. Reload the page.'); } }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_admin(): bool { return (bool)(current_user()['is_admin'] ?? false); }
function require_login(): void { if (!current_user()) redirect('/admin/login'); }
function require_admin(): void { require_login(); if (!is_admin()) { http_response_code(403); exit('Access denied.'); } }
function notice(string $message): void { $_SESSION['notice'] = $message; }
function take_notice(): ?string { $message=$_SESSION['notice'] ?? null; unset($_SESSION['notice']); return $message; }
function audit_change(string $type,int $id,string $action,array $before,array $after): void {
    exec_sql('INSERT INTO app_audit_log (actor_user_id,entity_type,entity_id,action,before_json,after_json) VALUES (?,?,?,?,?,?)',[
        (int)(current_user()['id'] ?? 0),$type,$id,$action,
        json_encode($before,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),
        json_encode($after,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),
    ]);
}
function wp_meta(int $postId, string $key): ?string { $r=row('SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key=? ORDER BY meta_id DESC LIMIT 1', [$postId,$key]); return $r['meta_value'] ?? null; }
function set_wp_meta(int $postId, string $key, string $value): void {
    $existing=row('SELECT meta_id FROM wp_postmeta WHERE post_id=? AND meta_key=? ORDER BY meta_id DESC LIMIT 1',[$postId,$key]);
    if ($existing) exec_sql('UPDATE wp_postmeta SET meta_value=? WHERE meta_id=?',[$value,$existing['meta_id']]);
    else exec_sql('INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,?,?)',[$postId,$key,$value]);
}
function wp_roles(int $userId): array {
    $r=row('SELECT meta_value FROM wp_usermeta WHERE user_id=? AND meta_key=? LIMIT 1',[$userId,'wp_capabilities']);
    $roles=$r ? @unserialize($r['meta_value'], ['allowed_classes'=>false]) : [];
    return is_array($roles) ? array_keys(array_filter($roles)) : [];
}
function verify_wp_password(string $password, string $hash): bool {
    if (strlen($password)>4096) return false;
    if (str_starts_with($hash,'$wp$')) {
        $prehash=base64_encode(hash_hmac('sha384',$password,'wp-sha384',true));
        return password_verify($prehash,substr($hash,3));
    }
    if (str_starts_with($hash,'$P$') || str_starts_with($hash,'$H$')) {
        $alphabet='./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $countLog2=strpos($alphabet,$hash[3] ?? '');
        if ($countLog2===false || $countLog2<7 || $countLog2>30) return false;
        $salt=substr($hash,4,8);
        if (strlen($salt)!==8) return false;
        $digest=md5($salt.$password,true);
        for ($i=0,$n=1<<$countLog2;$i<$n;$i++) $digest=md5($digest.$password,true);
        $encoded='';
        for ($i=0;$i<16;) {
            $v=ord($digest[$i++]); $encoded.=$alphabet[$v & 0x3f];
            if ($i<16) $v|=ord($digest[$i])<<8;
            $encoded.=$alphabet[($v>>6)&0x3f];
            if ($i++>=16) break;
            if ($i<16) $v|=ord($digest[$i])<<16;
            $encoded.=$alphabet[($v>>12)&0x3f];
            if ($i++>=16) break;
            $encoded.=$alphabet[($v>>18)&0x3f];
        }
        return hash_equals($hash,substr($hash,0,12).$encoded);
    }
    return password_verify($password,$hash);
}
function login(string $identity,string $password): bool {
    $key=(string)cfg('app_key','');
    if(strlen($key)<32) throw new RuntimeException('Configure app_key before allowing logins.');
    $identityHash=hash_hmac('sha256',mb_strtolower($identity),$key);
    $ipHash=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR'] ?? 'local'),$key);
    $recent=(int)(row("SELECT COUNT(*) n FROM app_login_attempts WHERE success=0 AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE) AND (identity_hash=? OR ip_hash=?)",[$identityHash,$ipHash])['n'] ?? 0);
    if($recent>=10) return false;
    $user=row('SELECT ID,user_login,user_email,display_name,user_pass FROM wp_users WHERE user_login=? OR user_email=? LIMIT 1',[$identity,$identity]);
    $valid=$user && verify_wp_password($password,$user['user_pass']);
    exec_sql('INSERT INTO app_login_attempts (identity_hash,ip_hash,success) VALUES (?,?,?)',[$identityHash,$ipHash,$valid?1:0]);
    if (!$valid) return false;
    $roles=wp_roles((int)$user['ID']);
    session_regenerate_id(true);
    $_SESSION['user']=['id'=>(int)$user['ID'],'name'=>$user['display_name'],'email'=>$user['user_email'],'roles'=>$roles,'is_admin'=>(bool)array_intersect($roles,['administrator','shop_manager'])];
    return true;
}
function currency(float|int|string $value): string { return number_format((float)$value,2,',',' ') . ' ' . h((string)cfg('currency','CHF')); }
function slug(string $value): string { return preg_replace('/[^a-z0-9-]+/','-',strtolower(trim($value))) ?: ''; }
