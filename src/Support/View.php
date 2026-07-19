<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class View
{
    public static function render(Response $response, string $template, array $data = [], int $status = 200): Response
    {
        $content = self::fetch($template, $data);
        $html = self::fetch('layout', array_merge($data, ['content' => $content]));
        $response->getBody()->write($html);
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public static function fetch(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/templates/' . $template . '.php';
        return (string)ob_get_clean();
    }
}
