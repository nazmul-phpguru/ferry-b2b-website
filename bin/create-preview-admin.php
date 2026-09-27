<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);
$config = require dirname(__DIR__) . '/config.php';
if ($config['db_name'] !== 'ferry_app') {
    fwrite(STDERR, "Preview account creation is limited to the local test database.\n");
    exit(1);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']),
    $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$login = 'preview_admin';
$email = 'preview-admin@localhost.invalid';
$query = $pdo->prepare('SELECT ID FROM wp_users WHERE user_login=? LIMIT 1');
$query->execute([$login]);
if ($query->fetchColumn()) {
    fwrite(STDERR, "Preview account already exists; credentials cannot be recovered.\n");
    exit(1);
}
$password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
$pdo->beginTransaction();
try {
    $query = $pdo->prepare('INSERT INTO wp_users (user_login,user_pass,user_nicename,user_email,user_registered,user_status,display_name) VALUES (?,?,?,?,NOW(),0,?)');
    $query->execute([$login, password_hash($password, PASSWORD_DEFAULT), $login, $email, 'Preview Administrator']);
    $id = (int)$pdo->lastInsertId();
    $query = $pdo->prepare('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)');
    $query->execute([$id, 'wp_capabilities', serialize(['administrator' => true])]);
    $query->execute([$id, 'wp_user_level', '10']);
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
echo "Preview username: $login\nPreview password: $password\n";
