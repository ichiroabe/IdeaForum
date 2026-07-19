<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\Auth;
use App\Support\Db;
use App\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * 付箋ボード。スレッド1件につき1枚。
 * 画面はJSで操作するため、ここはすべてJSONを返す。
 */
final class NoteController
{
    private const MAX_NOTES_PER_IDEA = 200;
    private const MAX_BODY = 500;

    /** ボード全体を返す (未ログインでも閲覧可) */
    public function index(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);

        $notes = Db::query(
            "SELECT n.id, n.body, n.pos_x, n.pos_y, n.color, n.source_post_id,
                    n.user_id, u.display_name
             FROM notes n JOIN users u ON u.id = n.user_id
             WHERE n.idea_id = ? ORDER BY n.id",
            [$idea['id']]
        )->fetchAll();

        $links = Db::query(
            'SELECT id, from_note_id, to_note_id, label FROM note_links WHERE idea_id = ? ORDER BY id',
            [$idea['id']]
        )->fetchAll();

        return self::json($response, [
            'canEdit' => self::canEdit(),
            'me'      => Auth::id(),
            'notes'   => array_map(self::castNote(...), $notes),
            'links'   => array_map(static fn($l) => [
                'id'    => (int)$l['id'],
                'from'  => (int)$l['from_note_id'],
                'to'    => (int)$l['to_note_id'],
                'label' => $l['label'],
            ], $links),
        ]);
    }

    public function createNote(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $userId = (int)Auth::id();
        $data = self::input($request);

        if (!RateLimiter::hit('note:user:' . $userId, 300, 86400)) {
            return self::json($response, ['error' => '本日の付箋作成数の上限に達しました。'], 429);
        }

        $count = (int)Db::query('SELECT COUNT(*) FROM notes WHERE idea_id = ?', [$idea['id']])->fetchColumn();
        if ($count >= self::MAX_NOTES_PER_IDEA) {
            return self::json($response, ['error' => 'このボードの付箋が上限に達しています。'], 400);
        }

        $body = self::trimBody((string)($data['body'] ?? ''));
        if ($body === '') {
            return self::json($response, ['error' => '付箋の内容を入力してください。'], 400);
        }

        // 返信から作る場合、その返信が同じスレッドのものか確認する
        $sourcePostId = null;
        if (!empty($data['source_post_id'])) {
            $post = Db::query(
                'SELECT id FROM posts WHERE id = ? AND idea_id = ?',
                [(int)$data['source_post_id'], $idea['id']]
            )->fetch();
            if ($post) {
                $sourcePostId = (int)$post['id'];
            }
        }

        $now = Db::now();
        Db::query(
            'INSERT INTO notes (idea_id, user_id, body, pos_x, pos_y, color, source_post_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $idea['id'], $userId, $body,
                self::clampPos($data['pos_x'] ?? 40),
                self::clampPos($data['pos_y'] ?? 40),
                self::clampColor($data['color'] ?? 'yellow'),
                $sourcePostId, $now, $now,
            ]
        );
        $id = Db::lastId();

        $note = Db::query(
            "SELECT n.id, n.body, n.pos_x, n.pos_y, n.color, n.source_post_id, n.user_id, u.display_name
             FROM notes n JOIN users u ON u.id = n.user_id WHERE n.id = ?",
            [$id]
        )->fetch();

        return self::json($response, ['note' => self::castNote($note)], 201);
    }

    public function updateNote(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $note = self::findNote((int)$args['noteId'], (int)$idea['id'], $request);
        $data = self::input($request);

        $fields = [];
        $params = [];
        // 位置だけの更新(ドラッグ)が最も頻繁なので、渡された項目だけを更新する
        if (array_key_exists('pos_x', $data)) { $fields[] = 'pos_x = ?'; $params[] = self::clampPos($data['pos_x']); }
        if (array_key_exists('pos_y', $data)) { $fields[] = 'pos_y = ?'; $params[] = self::clampPos($data['pos_y']); }
        if (array_key_exists('color', $data)) { $fields[] = 'color = ?'; $params[] = self::clampColor($data['color']); }
        if (array_key_exists('body', $data)) {
            $body = self::trimBody((string)$data['body']);
            if ($body === '') {
                return self::json($response, ['error' => '付箋の内容を入力してください。'], 400);
            }
            $fields[] = 'body = ?';
            $params[] = $body;
        }
        if (!$fields) {
            return self::json($response, ['error' => '更新する項目がありません。'], 400);
        }

        $fields[] = 'updated_at = ?';
        $params[] = Db::now();
        $params[] = $note['id'];
        Db::query('UPDATE notes SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);

        return self::json($response, ['ok' => true]);
    }

    public function deleteNote(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $note = self::findNote((int)$args['noteId'], (int)$idea['id'], $request);

        // つながっている線も一緒に消える (ON DELETE CASCADE)
        Db::query('DELETE FROM notes WHERE id = ?', [$note['id']]);
        return self::json($response, ['ok' => true]);
    }

    public function createLink(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $userId = (int)Auth::id();
        $data = self::input($request);

        $from = (int)($data['from'] ?? 0);
        $to   = (int)($data['to'] ?? 0);
        if ($from === $to) {
            return self::json($response, ['error' => '同じ付箋同士はつなげません。'], 400);
        }

        // 両方が本当にこのスレッドの付箋か確認する
        $ok = (int)Db::query(
            'SELECT COUNT(*) FROM notes WHERE id IN (?, ?) AND idea_id = ?',
            [$from, $to, $idea['id']]
        )->fetchColumn();
        if ($ok !== 2) {
            return self::json($response, ['error' => '対象の付箋が見つかりません。'], 400);
        }

        $label = mb_substr(trim((string)($data['label'] ?? '')), 0, 50);
        try {
            Db::query(
                'INSERT INTO note_links (idea_id, from_note_id, to_note_id, label, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$idea['id'], $from, $to, $label !== '' ? $label : null, $userId, Db::now()]
            );
        } catch (\PDOException $e) {
            // UNIQUE制約 = 既に同じ向きの線がある
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return self::json($response, ['error' => 'その2つは既につながっています。'], 409);
            }
            throw $e;
        }

        return self::json($response, [
            'link' => ['id' => Db::lastId(), 'from' => $from, 'to' => $to, 'label' => $label ?: null],
        ], 201);
    }

    public function deleteLink(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $deleted = Db::query(
            'DELETE FROM note_links WHERE id = ? AND idea_id = ?',
            [(int)$args['linkId'], $idea['id']]
        )->rowCount();

        if ($deleted === 0) {
            throw new HttpNotFoundException($request);
        }
        return self::json($response, ['ok' => true]);
    }

    // --- 補助 ---------------------------------------------------------------

    private static function canEdit(): bool
    {
        $user = Auth::user();
        return $user !== null && $user['status'] === 'active';
    }

    private static function findIdea(int $id, Request $request): array
    {
        $idea = Db::query('SELECT * FROM ideas WHERE id = ?', [$id])->fetch();
        if (!$idea || ($idea['status'] === 'hidden' && !Auth::isAdmin())) {
            throw new HttpNotFoundException($request);
        }
        return $idea;
    }

    private static function findNote(int $noteId, int $ideaId, Request $request): array
    {
        $note = Db::query('SELECT * FROM notes WHERE id = ? AND idea_id = ?', [$noteId, $ideaId])->fetch();
        if (!$note) {
            throw new HttpNotFoundException($request);
        }
        return $note;
    }

    private static function castNote(array $n): array
    {
        return [
            'id'     => (int)$n['id'],
            'body'   => $n['body'],
            'x'      => (int)$n['pos_x'],
            'y'      => (int)$n['pos_y'],
            'color'  => $n['color'],
            'author' => $n['display_name'],
            'userId' => (int)$n['user_id'],
            'postId' => $n['source_post_id'] !== null ? (int)$n['source_post_id'] : null,
        ];
    }

    private static function trimBody(string $s): string
    {
        return mb_substr(trim(str_replace("\r\n", "\n", $s)), 0, self::MAX_BODY);
    }

    private static function clampPos(mixed $v): int
    {
        return max(0, min(6000, (int)$v));
    }

    private static function clampColor(mixed $v): string
    {
        $allowed = ['yellow', 'blue', 'green', 'pink', 'gray'];
        return in_array($v, $allowed, true) ? (string)$v : 'yellow';
    }

    private static function input(Request $request): array
    {
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
