<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\Auth;
use App\Support\Avatar;
use App\Support\Db;
use App\Support\IdeaAccess;
use App\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * 付箋ボード。スレッド1件につき1枚。
 *
 * 誰でも他人の付箋を編集・削除できる代わりに、発行者以外の操作には理由を必須にし、
 * 付箋1枚ごとに履歴を残す。削除は論理削除で、管理者が復元できる。
 */
final class NoteController
{
    private const MAX_NOTES_PER_IDEA = 200;
    private const MAX_BODY = 500;
    private const MAX_REASON = 200;

    /** 色と、その色が表す分類の既定の意味 */
    public const COLORS = [
        'yellow' => 'アイデア',
        'blue'   => '課題',
        'green'  => '決定',
        'pink'   => '要検討',
        'gray'   => '保留',
    ];

    /** ボード全体を返す (未ログインでも閲覧可) */
    public function index(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $showDeleted = Auth::isAdmin();

        $notes = Db::query(
            "SELECT n.id, n.body, n.pos_x, n.pos_y, n.color, n.is_target, n.source_post_id,
                    n.user_id, n.deleted_at, u.display_name, u.avatar_emoji, u.avatar_color,
                    (SELECT COUNT(*) FROM note_events e WHERE e.note_id = n.id) AS event_count
             FROM notes n JOIN users u ON u.id = n.user_id
             WHERE n.idea_id = ?" . ($showDeleted ? '' : ' AND n.deleted_at IS NULL') . '
             ORDER BY n.id',
            [$idea['id']]
        )->fetchAll();

        // 消された付箋につながる線は描かない
        $links = Db::query(
            'SELECT l.id, l.from_note_id, l.to_note_id, l.label
             FROM note_links l
             JOIN notes nf ON nf.id = l.from_note_id AND nf.deleted_at IS NULL
             JOIN notes nt ON nt.id = l.to_note_id   AND nt.deleted_at IS NULL
             WHERE l.idea_id = ? ORDER BY l.id',
            [$idea['id']]
        )->fetchAll();

        return self::json($response, [
            'canEdit' => self::canEdit(),
            'isAdmin' => Auth::isAdmin(),
            'me'      => Auth::id(),
            'colors'  => self::COLORS,
            'notes'   => array_map(self::castNote(...), $notes),
            'links'   => array_map(static fn($l) => [
                'id'    => (int)$l['id'],
                'from'  => (int)$l['from_note_id'],
                'to'    => (int)$l['to_note_id'],
                'label' => $l['label'],
            ], $links),
        ]);
    }

    /** 付箋1枚の履歴 */
    public function history(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $noteId = (int)$args['noteId'];

        $events = Db::query(
            "SELECT e.action, e.reason, e.before_body, e.after_body,
                    e.before_color, e.after_color, e.created_at, u.display_name
             FROM note_events e JOIN users u ON u.id = e.actor_id
             WHERE e.idea_id = ? AND e.note_id = ?
             ORDER BY e.id",
            [$idea['id'], $noteId]
        )->fetchAll();

        return self::json($response, [
            'events' => array_map(static fn($e) => [
                'action'     => $e['action'],
                'actor'      => $e['display_name'],
                'reason'     => $e['reason'],
                'beforeBody' => $e['before_body'],
                'afterBody'  => $e['after_body'],
                'beforeColor'=> $e['before_color'],
                'afterColor' => $e['after_color'],
                'at'         => $e['created_at'],
            ], $events),
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

        $count = (int)Db::query(
            'SELECT COUNT(*) FROM notes WHERE idea_id = ? AND deleted_at IS NULL',
            [$idea['id']]
        )->fetchColumn();
        if ($count >= self::MAX_NOTES_PER_IDEA) {
            return self::json($response, ['error' => 'このボードの付箋が上限に達しています。'], 400);
        }

        $body = self::trimBody((string)($data['body'] ?? ''));
        if ($body === '') {
            return self::json($response, ['error' => '付箋の内容を入力してください。'], 400);
        }

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

        $color = self::clampColor($data['color'] ?? 'yellow');
        $now = Db::now();
        Db::query(
            'INSERT INTO notes (idea_id, user_id, body, pos_x, pos_y, color, source_post_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $idea['id'], $userId, $body,
                self::clampPos($data['pos_x'] ?? 40),
                self::clampPos($data['pos_y'] ?? 40),
                $color, $sourcePostId, $now, $now,
            ]
        );
        $id = Db::lastId();
        self::record((int)$idea['id'], $id, $userId, 'create', null, ['after_body' => $body, 'after_color' => $color]);

        $note = Db::query(
            "SELECT n.id, n.body, n.pos_x, n.pos_y, n.color, n.is_target, n.source_post_id, n.user_id, n.deleted_at,
                    u.display_name, u.avatar_emoji, u.avatar_color, 1 AS event_count
             FROM notes n JOIN users u ON u.id = n.user_id WHERE n.id = ?",
            [$id]
        )->fetch();

        return self::json($response, ['note' => self::castNote($note)], 201);
    }

    public function updateNote(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $note = self::findNote((int)$args['noteId'], (int)$idea['id'], $request);
        $userId = (int)Auth::id();
        $data = self::input($request);

        if ($note['deleted_at'] !== null) {
            return self::json($response, ['error' => 'この付箋は削除されています。'], 400);
        }

        $isOwner = ((int)$note['user_id'] === $userId);
        $reason = self::trimReason((string)($data['reason'] ?? ''));

        // 位置だけの移動は記録も理由も求めない(ドラッグのたびに履歴が埋まるため)
        $changesContent = array_key_exists('body', $data) || array_key_exists('color', $data);
        if ($changesContent && !$isOwner && $reason === '') {
            return self::json($response, [
                'error'         => '他の人の付箋を変更するには理由が必要です。',
                'reasonRequired' => true,
            ], 422);
        }

        $fields = [];
        $params = [];
        if (array_key_exists('pos_x', $data)) { $fields[] = 'pos_x = ?'; $params[] = self::clampPos($data['pos_x']); }
        if (array_key_exists('pos_y', $data)) { $fields[] = 'pos_y = ?'; $params[] = self::clampPos($data['pos_y']); }
        // 実装対象の印。誰でも付け外しでき、理由も履歴も求めない
        // (内容を変えるわけではなく、絞り込みの目印にすぎないため)。
        if (array_key_exists('is_target', $data)) {
            $fields[] = 'is_target = ?';
            $params[] = !empty($data['is_target']) ? 1 : 0;
        }

        $newColor = null;
        if (array_key_exists('color', $data)) {
            $newColor = self::clampColor($data['color']);
            $fields[] = 'color = ?';
            $params[] = $newColor;
        }
        $newBody = null;
        if (array_key_exists('body', $data)) {
            $newBody = self::trimBody((string)$data['body']);
            if ($newBody === '') {
                return self::json($response, ['error' => '付箋の内容を入力してください。'], 400);
            }
            $fields[] = 'body = ?';
            $params[] = $newBody;
        }
        if (!$fields) {
            return self::json($response, ['error' => '更新する項目がありません。'], 400);
        }

        $fields[] = 'updated_at = ?';
        $params[] = Db::now();
        $params[] = $note['id'];
        Db::query('UPDATE notes SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);

        // 本文と色は別々の出来事として記録し、履歴を読みやすくする
        if ($newBody !== null && $newBody !== $note['body']) {
            self::record((int)$idea['id'], (int)$note['id'], $userId, 'edit', $isOwner ? null : $reason, [
                'before_body' => $note['body'],
                'after_body'  => $newBody,
            ]);
        }
        if ($newColor !== null && $newColor !== $note['color']) {
            self::record((int)$idea['id'], (int)$note['id'], $userId, 'color', $isOwner ? null : $reason, [
                'before_color' => $note['color'],
                'after_color'  => $newColor,
            ]);
        }

        return self::json($response, ['ok' => true]);
    }

    public function deleteNote(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $note = self::findNote((int)$args['noteId'], (int)$idea['id'], $request);
        $userId = (int)Auth::id();
        $data = self::input($request);

        if ($note['deleted_at'] !== null) {
            return self::json($response, ['error' => 'この付箋は既に削除されています。'], 400);
        }

        $isOwner = ((int)$note['user_id'] === $userId);
        $reason = self::trimReason((string)($data['reason'] ?? ''));
        if (!$isOwner && $reason === '') {
            return self::json($response, [
                'error'          => '他の人の付箋を削除するには理由が必要です。',
                'reasonRequired' => true,
            ], 422);
        }

        // 論理削除。線も付箋本体も残すので、復元すれば元通りになる。
        Db::query(
            'UPDATE notes SET deleted_at = ?, deleted_by = ? WHERE id = ?',
            [Db::now(), $userId, $note['id']]
        );
        self::record((int)$idea['id'], (int)$note['id'], $userId, 'delete', $isOwner ? null : $reason, [
            'before_body' => $note['body'],
        ]);

        return self::json($response, ['ok' => true]);
    }

    /** 管理者による復元 */
    public function restoreNote(Request $request, Response $response, array $args): Response
    {
        $idea = self::findIdea((int)$args['id'], $request);
        $note = self::findNote((int)$args['noteId'], (int)$idea['id'], $request);

        if ($note['deleted_at'] === null) {
            return self::json($response, ['error' => 'この付箋は削除されていません。'], 400);
        }
        Db::query('UPDATE notes SET deleted_at = NULL, deleted_by = NULL WHERE id = ?', [$note['id']]);
        self::record((int)$idea['id'], (int)$note['id'], (int)Auth::id(), 'restore', null, [
            'after_body' => $note['body'],
        ]);

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

        $ok = (int)Db::query(
            'SELECT COUNT(*) FROM notes WHERE id IN (?, ?) AND idea_id = ? AND deleted_at IS NULL',
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

    private static function record(
        int $ideaId, int $noteId, int $actorId, string $action, ?string $reason, array $detail
    ): void {
        Db::query(
            'INSERT INTO note_events
                (idea_id, note_id, actor_id, action, reason,
                 before_body, after_body, before_color, after_color, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $ideaId, $noteId, $actorId, $action, $reason !== '' ? $reason : null,
                $detail['before_body']  ?? null,
                $detail['after_body']   ?? null,
                $detail['before_color'] ?? null,
                $detail['after_color']  ?? null,
                Db::now(),
            ]
        );
    }

    private static function canEdit(): bool
    {
        $user = Auth::user();
        return $user !== null && $user['status'] === 'active';
    }

    private static function findIdea(int $id, Request $request): array
    {
        $idea = Db::query('SELECT * FROM ideas WHERE id = ?', [$id])->fetch();
        if (!$idea || !IdeaAccess::canView($idea)) {
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
            'id'      => (int)$n['id'],
            'body'    => $n['body'],
            'x'       => (int)$n['pos_x'],
            'y'       => (int)$n['pos_y'],
            'color'   => $n['color'],
            'target'  => (bool)($n['is_target'] ?? false),
            'author'  => $n['display_name'],
            'userId'  => (int)$n['user_id'],
            // 付箋に作成者のアバターを出すため、色と絵柄も一緒に返す
            'avatar'  => [
                'label' => Avatar::labelFor($n),
                'color' => Avatar::colorFor(['id' => $n['user_id']] + $n),
            ],
            'postId'  => $n['source_post_id'] !== null ? (int)$n['source_post_id'] : null,
            'deleted' => $n['deleted_at'] !== null,
            'events'  => (int)($n['event_count'] ?? 0),
        ];
    }

    private static function trimBody(string $s): string
    {
        return mb_substr(trim(str_replace("\r\n", "\n", $s)), 0, self::MAX_BODY);
    }

    private static function trimReason(string $s): string
    {
        return mb_substr(trim($s), 0, self::MAX_REASON);
    }

    private static function clampPos(mixed $v): int
    {
        return max(0, min(6000, (int)$v));
    }

    private static function clampColor(mixed $v): string
    {
        return array_key_exists((string)$v, self::COLORS) ? (string)$v : 'yellow';
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
