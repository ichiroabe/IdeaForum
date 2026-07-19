<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\Db;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminController
{
    public function dashboard(Request $request, Response $response): Response
    {
        $reports = Db::query(
            "SELECT r.*, u.display_name AS reporter_name,
                    CASE r.target_type
                        WHEN 'idea' THEN (SELECT title FROM ideas WHERE id = r.target_id)
                        WHEN 'post' THEN (SELECT CONCAT(LEFT(body, 80), '…') FROM posts WHERE id = r.target_id)
                    END AS target_preview,
                    CASE r.target_type
                        WHEN 'idea' THEN (SELECT status FROM ideas WHERE id = r.target_id)
                        WHEN 'post' THEN (SELECT status FROM posts WHERE id = r.target_id)
                    END AS target_status,
                    CASE r.target_type
                        WHEN 'idea' THEN r.target_id
                        WHEN 'post' THEN (SELECT idea_id FROM posts WHERE id = r.target_id)
                    END AS idea_id
             FROM reports r JOIN users u ON u.id = r.reporter_id
             WHERE r.resolved_at IS NULL
             ORDER BY r.created_at DESC LIMIT 100"
        )->fetchAll();

        $users = Db::query(
            'SELECT id, email, display_name, role, status, created_at, last_login_at
             FROM users ORDER BY created_at DESC LIMIT 100'
        )->fetchAll();

        return View::render($response, 'admin', [
            'title'   => '管理',
            'reports' => $reports,
            'users'   => $users,
        ]);
    }

    // 対象の表示/非表示切替
    public function toggleVisibility(Request $request, Response $response): Response
    {
        $type = (string)($_POST['target_type'] ?? '');
        $id = (int)($_POST['target_id'] ?? 0);
        if ($type === 'idea') {
            Db::query("UPDATE ideas SET status = IF(status = 'hidden', 'open', 'hidden') WHERE id = ?", [$id]);
        } elseif ($type === 'post') {
            Db::query("UPDATE posts SET status = IF(status = 'hidden', 'visible', 'hidden') WHERE id = ?", [$id]);
        }
        Flash::add('success', '表示状態を切り替えました。');
        return redirect($response, (string)($_POST['back'] ?? '/admin'));
    }

    public function resolveReport(Request $request, Response $response): Response
    {
        Db::query('UPDATE reports SET resolved_at = ? WHERE id = ?', [Db::now(), (int)($_POST['report_id'] ?? 0)]);
        Flash::add('success', '通報を対応済みにしました。');
        return redirect($response, '/admin');
    }

    public function toggleBan(Request $request, Response $response): Response
    {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = Db::query('SELECT * FROM users WHERE id = ?', [$userId])->fetch();
        if ($user && $user['role'] !== 'admin') {
            $newStatus = $user['status'] === 'banned' ? 'active' : 'banned';
            Db::query('UPDATE users SET status = ? WHERE id = ?', [$newStatus, $userId]);
            Flash::add('success', "ユーザー「{$user['display_name']}」を" . ($newStatus === 'banned' ? '停止' : '復帰') . 'しました。');
        }
        return redirect($response, '/admin');
    }
}
