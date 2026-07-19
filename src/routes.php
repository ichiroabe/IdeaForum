<?php
declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\IdeaController;
use App\Controller\NoteController;
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

    // 状態を変えるメソッドは全ルートでCSRFトークン必須。
    // 画面のフォームは hidden の _csrf、JSON API は X-CSRF-Token ヘッダで送る。
    $csrf = function (Request $request, $handler) {
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }
        $token = $request->getHeaderLine('X-CSRF-Token') ?: ($_POST['_csrf'] ?? null);
        if (Csrf::check($token !== '' ? $token : null)) {
            return $handler->handle($request);
        }

        // APIにはJSONで返す(リダイレクトされてもJS側で扱えないため)
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $res = (new SlimResponse())->withStatus(419)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
            $res->getBody()->write(json_encode(
                ['error' => 'セッションが切れました。ページを再読み込みしてください。'],
                JSON_UNESCAPED_UNICODE
            ));
            return $res;
        }
        Flash::add('error', 'セッションが切れました。もう一度操作してください。');
        return redirect(new SlimResponse(), '/');
    };
    $app->add($csrf);

    // ログイン(メール確認済み)必須
    $requireActive = function (Request $request, $handler) {
        $user = Auth::user();
        $wantsJson = str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($user !== null && $user['status'] === 'active') {
            return $handler->handle($request);
        }

        // APIをリダイレクトするとfetchがHTMLを受け取ってしまうので、JSONで返す
        if ($wantsJson) {
            $res = (new SlimResponse())->withStatus(401)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
            $res->getBody()->write(json_encode(
                ['error' => $user === null ? 'ログインが必要です。' : 'メール確認が完了していません。'],
                JSON_UNESCAPED_UNICODE
            ));
            return $res;
        }

        if ($user === null) {
            Flash::add('error', 'ログインが必要です。');
            return redirect(new SlimResponse(), '/login');
        }
        Flash::add('error', 'メール確認が完了していません。');
        return redirect(new SlimResponse(), '/resend?email=' . urlencode($user['email']));
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

    // 付箋ボード。閲覧は誰でも、編集はメール確認済みのメンバー。
    $app->get('/ideas/{id:[0-9]+}/notes', [NoteController::class, 'index']);
    $app->get('/ideas/{id:[0-9]+}/notes/{noteId:[0-9]+}/history', [NoteController::class, 'history']);
    $app->post('/ideas/{id:[0-9]+}/notes', [NoteController::class, 'createNote'])->add($requireActive);
    $app->patch('/ideas/{id:[0-9]+}/notes/{noteId:[0-9]+}', [NoteController::class, 'updateNote'])->add($requireActive);
    $app->delete('/ideas/{id:[0-9]+}/notes/{noteId:[0-9]+}', [NoteController::class, 'deleteNote'])->add($requireActive);
    $app->post('/ideas/{id:[0-9]+}/notes/{noteId:[0-9]+}/restore', [NoteController::class, 'restoreNote'])->add($requireAdmin);
    $app->post('/ideas/{id:[0-9]+}/links', [NoteController::class, 'createLink'])->add($requireActive);
    $app->delete('/ideas/{id:[0-9]+}/links/{linkId:[0-9]+}', [NoteController::class, 'deleteLink'])->add($requireActive);

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
