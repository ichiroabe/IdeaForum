<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\Auth;
use App\Support\Avatar;
use App\Support\Db;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SettingsController
{
    public function show(Request $request, Response $response): Response
    {
        return View::render($response, 'settings', [
            'title' => '表示の設定',
            'user'  => Auth::user(),
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $user = Auth::user();

        $displayName = trim((string)($_POST['display_name'] ?? ''));
        if ($displayName === '' || mb_strlen($displayName) > 50) {
            Flash::add('error', '表示名は1〜50文字で入力してください。');
            return redirect($response, '/settings');
        }

        // 一覧にないものは弾く。空文字は「未設定に戻す」の意味で許す。
        $emoji = (string)($_POST['avatar_emoji'] ?? '');
        $color = (string)($_POST['avatar_color'] ?? '');
        $emoji = Avatar::isValidEmoji($emoji) ? $emoji : null;
        $color = Avatar::isValidColor($color) ? $color : null;

        Db::query(
            'UPDATE users SET display_name = ?, avatar_emoji = ?, avatar_color = ? WHERE id = ?',
            [$displayName, $emoji, $color, $user['id']]
        );

        Flash::add('success', '設定を保存しました。');
        return redirect($response, '/settings');
    }
}
