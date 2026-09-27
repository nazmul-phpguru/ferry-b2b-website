<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
db()->exec("CREATE TABLE IF NOT EXISTS app_audit_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 entity_type VARCHAR(40) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(40) NOT NULL,
 before_json LONGTEXT NULL,
 after_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY entity (entity_type,entity_id), KEY actor (actor_user_id), KEY created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS app_login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 identity_hash CHAR(64) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 success TINYINT(1) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY identity_time (identity_hash,created_at), KEY ip_time (ip_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->exec("CREATE TABLE IF NOT EXISTS app_wholesale_roles (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(64) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 description TEXT NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$seeded=(int)db()->query('SELECT COUNT(*) FROM app_wholesale_roles')->fetchColumn();
if ($seeded===0) {
    $option=row("SELECT option_value FROM wp_options WHERE option_name='wwp_options_registered_custom_roles' LIMIT 1");
    $value=$option['option_value'] ?? '';
    for ($i=0;$i<2 && is_string($value);$i++) {
        $decoded=@unserialize($value,['allowed_classes'=>false]);
        if ($decoded===false) break;
        $value=$decoded;
    }
    if (is_array($value)) {
        $insert=db()->prepare('INSERT IGNORE INTO app_wholesale_roles (slug,name,description) VALUES (?,?,?)');
        foreach ($value as $slug=>$details) {
            if (!is_string($slug) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$slug) || !is_array($details)) continue;
            $insert->execute([$slug,(string)($details['roleName'] ?? $slug),(string)($details['desc'] ?? '')]);
        }
    }
    $roleOption=row("SELECT option_value FROM wp_options WHERE option_name='wp_user_roles' LIMIT 1");
    $wpRoles=@unserialize($roleOption['option_value'] ?? '',['allowed_classes'=>false]);
    if (is_array($wpRoles)) {
        $priceKeys=rows("SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key LIKE '%_wholesale_price'");
        $known=array_fill_keys(array_column($priceKeys,'meta_key'),true);
        $insert=db()->prepare('INSERT IGNORE INTO app_wholesale_roles (slug,name,description) VALUES (?,?,?)');
        foreach ($wpRoles as $slug=>$details) {
            if (!is_string($slug) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$slug) || !isset($known[$slug.'_wholesale_price'])) continue;
            $insert->execute([$slug,(string)($details['name'] ?? $slug),'Imported wholesale role']);
        }
    }
}
echo "Application tables ready.\n";
