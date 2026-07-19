<?php
declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\IdeaController;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response as SlimResponse;

// リダイレクトヘルパー(テンプレート/コントローラ共用)。サブフォルダ設置に追随する。
function redirect(Response $response, string $path): Response
{
    // このファイルは use Slim\App; を持つため、先頭の \ は必須(App が Slim\App に解決されてしまう)
    return $response->withHeader('Location', \App\Support\App::path($path))->withStatus(302);
}

return function (App $app): void {

    // POSTは全ルートでCSRFトークン必須
    $csrf = function (Request $request, $handler) {
        if ($request->getMethod() === 'POST' && !Csrf::check($_POST['_csrf'] ?? null)) {
            Flash::add('error', 'セッションが切れました。もう一度操作してください。');
            return redirect(new SlimResponse(), '/');
        }
        return $handler->handle($request);
    };
    $app->add($csrf);

    // ログイン(メール確認済み)必須
    $requireActive = function (Request $request, $handler) {
        $user = Auth::user();
        if ($user === null) {
            Flash::add('error', 'ログインが必要です。');
            return redirect(new SlimResponse(), '/login');
        }
        if ($user['status'] !== 'active') {
            Flash::add('error', 'メール確認が完了していません。');
            return redirect(new SlimResponse(), '/resend?email=' . urlencode($user['email']));
        }
        return $handler->handle($request);
    };

    $requireAdmin = function (Request $request, $handler) {
        if (!Auth::isAdmin()) {
            throw new HttpForbiddenException($request);
        }
        return $handler->handle($request);
    };

    // 公開ページ
    $app->get('/', [IdeaController::class, 'index']);
    $app->get('/ideas/{id:[0-9]+}', [IdeaController::class, 'show']);
    $app->get('/ideas/{id:[0-9]+}/export.md', [IdeaController::class, 'export']);

    // 認証
    $app->get('/register', [AuthController::class, 'showRegister']);
    $app->post('/register', [AuthController::class, 'register']);
    $app->get('/verify', [AuthController::class, 'verify']);
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout']);
    $app->get('/resend', [AuthController::class, 'showResend']);
    $app->post('/resend', [AuthController::class, 'resend']);

    // 投稿系 (要ログイン+メール確認済み)
    $app->get('/ideas/new', [IdeaController::class, 'showNew'])->add($requireActive);
    $app->post('/ideas', [IdeaController::class, 'create'])->add($requireActive);
    $app->post('/ideas/{id:[0-9]+}/reply', [IdeaController::class, 'reply'])->add($requireActive);
    $app->post('/report', [IdeaController::class, 'report'])->add($requireActive);

    // 管理
    $app->get('/admin', [AdminController::class, 'dashboard'])->add($requireAdmin);
    $app->post('/admin/toggle-visibility', [AdminController::class, 'toggleVisibility'])->add($requireAdmin);
    $app->post('/admin/resolve-report', [AdminController::class, 'resolveReport'])->add($requireAdmin);
    $app->post('/admin/toggle-ban', [AdminController::class, 'toggleBan'])->add($requireAdmin);
};
