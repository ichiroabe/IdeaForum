<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HelpController
{
    public function index(Request $request, Response $response): Response
    {
        return View::render($response, 'help', [
            'title' => '使い方のヘルプ',
        ]);
    }
}
