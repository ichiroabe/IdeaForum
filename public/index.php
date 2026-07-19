<?php
declare(strict_types=1);

use App\Support\App as IdeaForum;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('config/config.php がありません。config.sample.php をコピーして作成してください。');
}
$config = require $configPath;

date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');
mb_internal_encoding('UTF-8');

IdeaForum::init($config);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => str_starts_with($config['base_url'], 'https://'),
    'path'     => '/',
]);
session_name('ideaforum_sid');
session_start();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// セキュリティヘッダ
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Referrer-Policy', 'same-origin');
});

$errorMiddleware = $app->addErrorMiddleware((bool)($config['debug'] ?? false), true, true);
$errorMiddleware->getDefaultErrorHandler()->forceContentType('text/html');

(require dirname(__DIR__) . '/src/routes.php')($app);

$app->run();
