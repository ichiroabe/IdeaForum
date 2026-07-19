<?php
// PHPビルトインサーバー用ルーター (php -S localhost:8080 -t public public/router.php)
// 実ファイルはそのまま配信し、それ以外は index.php に回す。本番(Apache)では .htaccess が担当。
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
