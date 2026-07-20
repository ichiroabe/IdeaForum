<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\App;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Flash;
use App\Support\IdeaAccess;
use App\Support\RateLimiter;
use App\Support\Text;
use App\Support\Unread;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

final class IdeaController
{
    private const PER_PAGE = 20;

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $tag = trim((string)($q['tag'] ?? ''));
        $search = trim((string)($q['q'] ?? ''));
        $page = max(1, (int)($q['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        // 誰に何が見えるかは IdeaAccess に集約している
        $where = [];
        $params = [];
        [$visCond, $visParams] = IdeaAccess::listCondition();
        if ($visCond !== null) {
            $where[] = $visCond;
            $params = array_merge($params, $visParams);
        }
        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM idea_tags it JOIN tags t ON t.id = it.tag_id WHERE it.idea_id = i.id AND t.name = ?)';
            $params[] = $tag;
        }
        if ($search !== '') {
            // 議論の中身は返信と付箋にあるので、そちらも探す。
            // タイトルと本文だけだと「あったはずなのに見つからない」が起きる。
            $where[] = '(i.title LIKE ? OR i.body LIKE ?
                         OR EXISTS (SELECT 1 FROM posts p
                                     WHERE p.idea_id = i.id AND p.deleted_at IS NULL
                                       AND p.status = \'visible\' AND p.body LIKE ?)
                         OR EXISTS (SELECT 1 FROM notes n
                                     WHERE n.idea_id = i.id AND n.deleted_at IS NULL
                                       AND n.body LIKE ?))';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        // 管理者かつ絞り込み無しだと条件が1つも無くなるため、その場合に備える
        $whereSql = $where ? implode(' AND ', $where) : '1';

        $total = (int)Db::query("SELECT COUNT(*) FROM ideas i WHERE {$whereSql}", $params)->fetchColumn();
        $ideas = Db::query(
            "SELECT i.*, u.display_name, u.avatar_emoji, u.avatar_color
             FROM ideas i JOIN users u ON u.id = i.user_id
             WHERE {$whereSql}
             ORDER BY i.updated_at DESC
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        )->fetchAll();

        $tagsByIdea = self::tagsFor(array_column($ideas, 'id'));
        $allTags = Db::query(
            "SELECT t.name, COUNT(*) AS cnt
             FROM tags t JOIN idea_tags it ON it.tag_id = t.id
             JOIN ideas i ON i.id = it.idea_id AND i.status <> 'hidden'
             GROUP BY t.id, t.name ORDER BY cnt DESC, t.name LIMIT 30"
        )->fetchAll();

        return View::render($response, 'home', [
            'title'      => null,
            'ideas'      => $ideas,
            'unreadIds'  => Unread::idsWithUpdates(array_map('intval', array_column($ideas, 'id'))),
            'tagsByIdea' => $tagsByIdea,
            'allTags'    => $allTags,
            'tag'        => $tag,
            'search'     => $search,
            'page'       => $page,
            'totalPages' => max(1, (int)ceil($total / self::PER_PAGE)),
        ]);
    }

    public function showNew(Request $request, Response $response): Response
    {
        return View::render($response, 'idea_new', ['title' => '新しいアイディア']);
    }

    public function create(Request $request, Response $response): Response
    {
        $userId = (int)Auth::id();

        if (trim((string)($_POST['website'] ?? '')) !== '') {
            return redirect($response, '/'); // ハニーポット
        }
        if (!RateLimiter::hit('idea:user:' . $userId, (int)App::config('limits.ideas_per_user_per_day', 10), 86400)) {
            Flash::add('error', '本日のアイディア投稿数の上限に達しました。');
            return redirect($response, '/');
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $body = str_replace("\r\n", "\n", trim((string)($_POST['body'] ?? '')));
        $tags = Text::parseTags((string)($_POST['tags'] ?? ''));

        if ($title === '' || mb_strlen($title) > 200) {
            Flash::add('error', 'タイトルは1〜200文字で入力してください。');
            return redirect($response, '/ideas/new');
        }
        if ($body === '' || mb_strlen($body) > 20000) {
            Flash::add('error', '本文は1〜20000文字で入力してください。');
            return redirect($response, '/ideas/new');
        }

        $now = Db::now();
        Db::query(
            'INSERT INTO ideas (user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $title, $body, $now, $now]
        );
        $ideaId = Db::lastId();
        self::attachTags($ideaId, $tags);

        Flash::add('success', 'アイディアを投稿しました。');
        return redirect($response, '/ideas/' . $ideaId);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);
        $author = Db::query(
            'SELECT display_name, avatar_emoji, avatar_color FROM users WHERE id = ?',
            [$idea['user_id']]
        )->fetch() ?: [];
        $idea['author_name']  = (string)($author['display_name'] ?? '');
        $idea['author_emoji'] = $author['avatar_emoji'] ?? null;
        $idea['author_color'] = $author['avatar_color'] ?? null;

        $posts = Db::query(
            "SELECT p.*, u.display_name, u.avatar_emoji, u.avatar_color
             FROM posts p JOIN users u ON u.id = p.user_id
             WHERE p.idea_id = ? ORDER BY p.created_at",
            [$idea['id']]
        )->fetchAll();
        $tags = self::tagsFor([(int)$idea['id']])[(int)$idea['id']] ?? [];

        // 開いた時点で既読にする。以降の更新だけが「新着」として出る。
        Unread::markRead((int)$idea['id']);

        return View::render($response, 'idea_show', [
            'title' => $idea['title'],
            'idea'  => $idea,
            'posts' => $posts,
            'tags'  => $tags,
        ]);
    }

    public function reply(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);
        $userId = (int)Auth::id();

        if (trim((string)($_POST['website'] ?? '')) !== '') {
            return redirect($response, '/ideas/' . $idea['id']);
        }
        if ($idea['status'] === 'closed') {
            Flash::add('error', 'このスレッドは閉じられています。');
            return redirect($response, '/ideas/' . $idea['id']);
        }
        $cooldown = (int)App::config('limits.post_cooldown_seconds', 20);
        if (!RateLimiter::hit('postcd:user:' . $userId, 1, $cooldown)) {
            Flash::add('error', "投稿間隔が短すぎます。{$cooldown}秒ほど空けてください。");
            return redirect($response, '/ideas/' . $idea['id']);
        }
        if (!RateLimiter::hit('post:user:' . $userId, (int)App::config('limits.posts_per_user_per_day', 60), 86400)) {
            Flash::add('error', '本日の投稿数の上限に達しました。');
            return redirect($response, '/ideas/' . $idea['id']);
        }

        $body = str_replace("\r\n", "\n", trim((string)($_POST['body'] ?? '')));
        if ($body === '' || mb_strlen($body) > 10000) {
            Flash::add('error', '本文は1〜10000文字で入力してください。');
            return redirect($response, '/ideas/' . $idea['id']);
        }

        $now = Db::now();
        Db::query(
            'INSERT INTO posts (idea_id, user_id, body, created_at) VALUES (?, ?, ?, ?)',
            [$idea['id'], $userId, $body, $now]
        );
        // lastId() は後続のUPDATEで0に戻るため、INSERT直後に控える
        $postId = Db::lastId();
        Db::query('UPDATE ideas SET posts_count = posts_count + 1, updated_at = ? WHERE id = ?', [$now, $idea['id']]);

        Flash::add('success', '返信を投稿しました。');
        return redirect($response, '/ideas/' . $idea['id'] . '#post-' . $postId);
    }

    public function report(Request $request, Response $response): Response
    {
        $userId = (int)Auth::id();
        if (!RateLimiter::hit('report:user:' . $userId, 10, 86400)) {
            Flash::add('error', '本日の通報回数の上限に達しました。');
            return redirect($response, '/');
        }
        $type = (string)($_POST['target_type'] ?? '');
        $targetId = (int)($_POST['target_id'] ?? 0);
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
        $back = $type === 'idea' ? '/ideas/' . $targetId : (string)($_POST['back'] ?? '/');

        if (!in_array($type, ['idea', 'post'], true) || $targetId <= 0 || $reason === '') {
            Flash::add('error', '通報内容が不正です。');
            return redirect($response, '/');
        }
        Db::query(
            'INSERT INTO reports (reporter_id, target_type, target_id, reason, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $type, $targetId, $reason, Db::now()]
        );
        Flash::add('success', '通報を受け付けました。ご協力ありがとうございます。');
        return redirect($response, $back);
    }

    // スレッドをopenManidoc取り込み用Markdownとしてダウンロード
    public function export(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);
        $posts = Db::query(
            "SELECT p.*, u.display_name FROM posts p JOIN users u ON u.id = p.user_id
             WHERE p.idea_id = ? AND p.status = 'visible' AND p.deleted_at IS NULL
             ORDER BY p.created_at",
            [$idea['id']]
        )->fetchAll();
        $tags = self::tagsFor([(int)$idea['id']])[(int)$idea['id']] ?? [];
        $author = Db::query('SELECT display_name FROM users WHERE id = ?', [$idea['user_id']])->fetchColumn();

        $md = '# ' . $idea['title'] . "\n\n";
        $meta = [];
        $meta[] = '- 起案: ' . $author . ' (' . $idea['created_at'] . ')';
        if ($tags) {
            $meta[] = '- タグ: ' . implode(' ', array_map(fn($t) => '#' . $t, $tags));
        }
        $meta[] = '- 出典: ' . App::baseUrl('/ideas/' . $idea['id']);
        $md .= implode("\n", $meta) . "\n\n";
        $md .= $idea['body'] . "\n";

        foreach ($posts as $p) {
            $md .= "\n## " . $p['display_name'] . ' (' . $p['created_at'] . ")\n\n";
            $md .= $p['body'] . "\n";
        }

        $md .= self::boardMarkdown((int)$idea['id']);

        $response->getBody()->write($md);
        return $response
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="idea-' . $idea['id'] . '.md"');
    }

    /**
     * 「実装対象」の印が付いた付箋だけを、指示書の型でMarkdownにする。
     *
     * 通常のMD出力が議論の記録なのに対し、こちらは次の作業に渡すためのもの。
     * 共有の場に置いた思考を手元へ帰す経路なので、判断の経緯(誰が何を
     * なぜ変えたか)も一緒に持ち出せるようにしている。
     */
    public function exportSpec(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);
        $author = (string)Db::query('SELECT display_name FROM users WHERE id = ?', [$idea['user_id']])->fetchColumn();
        $tags = self::tagsFor([(int)$idea['id']])[(int)$idea['id']] ?? [];

        $targets = Db::query(
            "SELECT n.id, n.body, n.color, u.display_name
             FROM notes n JOIN users u ON u.id = n.user_id
             WHERE n.idea_id = ? AND n.is_target = 1 AND n.deleted_at IS NULL
             ORDER BY n.id",
            [$idea['id']]
        )->fetchAll();

        $md  = '# ' . $idea['title'] . "\n\n";
        // 本文にも見出しがあるため、1段下げて指示書の構成に収める
        $md .= "## 背景\n\n" . self::demoteHeadings($idea['body'], 2) . "\n\n";

        $md .= "## 対象\n\n";
        if (!$targets) {
            $md .= "実装対象の付箋が選ばれていません。ボードで付箋に「的」印を付けてください。\n\n";
        } else {
            foreach ($targets as $t) {
                $label = NoteController::COLORS[$t['color']] ?? $t['color'];
                $md .= '### ' . self::firstLine($t['body']) . " ({$label})\n\n";
                // 1行目は見出しにしたので、残りだけを本文として出す
                $rest = trim(self::withoutFirstLine($t['body']));
                if ($rest !== '') {
                    $md .= $rest . "\n\n";
                }

                $events = Db::query(
                    "SELECT e.action, e.reason, e.before_body, e.before_color, e.after_color,
                            e.created_at, u.display_name
                     FROM note_events e JOIN users u ON u.id = e.actor_id
                     WHERE e.note_id = ? AND e.reason IS NOT NULL ORDER BY e.id",
                    [$t['id']]
                )->fetchAll();
                if ($events) {
                    // 理由が付いた変更だけを載せる。「なぜそうなったか」が
                    // 抜けると、受け取った側が判断を再現できない。
                    $md .= "検討の経緯:\n\n";
                    foreach ($events as $ev) {
                        $what = match ($ev['action']) {
                            'edit'   => '文面を変更',
                            'color'  => '分類を変更 (' . ($ev['before_color'] ?? '') . ' → ' . ($ev['after_color'] ?? '') . ')',
                            'delete' => '削除',
                            default  => $ev['action'],
                        };
                        $md .= '- ' . $ev['created_at'] . ' ' . $ev['display_name'] . ' が' . $what
                             . ' — ' . $ev['reason'] . "\n";
                    }
                    $md .= "\n";
                }
            }
        }

        $board = self::boardMarkdown((int)$idea['id'], false);
        $md .= "## 関係\n\n" . ($board !== '' ? $board : "(付箋がありません)\n");

        $md .= "\n## 受け入れの目安\n\n";
        $md .= "- 上記「対象」の各項目が満たされていること\n";
        $md .= "- 既存の動作を壊していないこと\n\n";

        $md .= "## 出典\n\n";
        $md .= '- スレッド: ' . App::baseUrl('/ideas/' . $idea['id']) . "\n";
        $md .= '- 起案: ' . $author . ' (' . $idea['created_at'] . ")\n";
        if ($tags) {
            $md .= '- タグ: ' . implode(' ', array_map(fn($t) => '#' . $t, $tags)) . "\n";
        }
        if (!empty($idea['impl_url'])) {
            $md .= '- 実装: ' . $idea['impl_url'] . "\n";
        }

        $name = 'spec-' . $idea['id'] . '-' . preg_replace('/[^\w\-]+/u', '_', mb_substr($idea['title'], 0, 24)) . '.md';
        $response->getBody()->write($md);
        return $response
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename*=UTF-8\'\'' . rawurlencode($name));
    }

    /** 実装結果の記録 (起案者と管理者のみ) */
    public function saveImpl(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);
        if (!Auth::isAdmin() && (int)$idea['user_id'] !== Auth::id()) {
            Flash::add('error', 'この記録は変更できません。');
            return redirect($response, '/ideas/' . $idea['id']);
        }
        $url = trim((string)($_POST['impl_url'] ?? ''));
        $note = mb_substr(trim((string)($_POST['impl_note'] ?? '')), 0, 300);
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            Flash::add('error', 'URLの形式が正しくありません。');
            return redirect($response, '/ideas/' . $idea['id']);
        }
        Db::query(
            'UPDATE ideas SET impl_url = ?, impl_note = ? WHERE id = ?',
            [$url !== '' ? mb_substr($url, 0, 500) : null, $note !== '' ? $note : null, $idea['id']]
        );
        Flash::add('success', '実装の記録を保存しました。');
        return redirect($response, '/ideas/' . $idea['id']);
    }

    private static function firstLine(string $s): string
    {
        $line = trim(strtok(str_replace("\r\n", "\n", $s), "\n") ?: '');
        return mb_strlen($line) > 60 ? mb_substr($line, 0, 60) . '…' : ($line !== '' ? $line : '(無題)');
    }

    private static function withoutFirstLine(string $s): string
    {
        $parts = explode("\n", str_replace("\r\n", "\n", $s), 2);
        return $parts[1] ?? '';
    }

    /**
     * 取り込んだ本文の見出しを $by 段だけ下げる。
     * 利用者が書いた「## 背景」がそのまま入ると、指示書側の見出しと
     * 同じ高さになって構成が崩れるため。
     */
    private static function demoteHeadings(string $md, int $by): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $md));
        $inFence = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = !$inFence;   // コード内の # は見出しではない
                continue;
            }
            if (!$inFence && preg_match('/^(#{1,6})(\s)/', $line, $m)) {
                $level = min(6, strlen($m[1]) + $by);
                $lines[$i] = str_repeat('#', $level) . substr($line, strlen($m[1]));
            }
        }
        return implode("\n", $lines);
    }

    /**
     * 付箋ボードを mermaid の図として書き出す。
     * openManidoc 側でノードの関係をそのまま図として取り込めるようにするため。
     */
    private static function boardMarkdown(int $ideaId, bool $withHeading = true): string
    {
        $notes = Db::query(
            'SELECT id, body, color FROM notes WHERE idea_id = ? ORDER BY id',
            [$ideaId]
        )->fetchAll();
        if (!$notes) {
            return '';
        }
        $links = Db::query(
            'SELECT from_note_id, to_note_id, label FROM note_links WHERE idea_id = ? ORDER BY id',
            [$ideaId]
        )->fetchAll();

        $md = ($withHeading ? "\n## 付箋ボード\n\n" : '') . "```mermaid\ngraph TD\n";
        foreach ($notes as $n) {
            $md .= sprintf("    N%d[\"%s\"]\n", $n['id'], self::mermaidText($n['body']));
        }
        foreach ($links as $l) {
            $label = trim((string)($l['label'] ?? ''));
            $md .= $label !== ''
                ? sprintf("    N%d -->|%s| N%d\n", $l['from_note_id'], self::mermaidText($label), $l['to_note_id'])
                : sprintf("    N%d --> N%d\n", $l['from_note_id'], $l['to_note_id']);
        }
        $md .= "```\n";
        return $md;
    }

    // mermaidのラベルを壊す文字を避ける(引用符・角括弧・改行・パイプ)
    private static function mermaidText(string $s): string
    {
        $s = str_replace(["\r\n", "\n", "\r"], ' ', $s);
        $s = str_replace(['"', '[', ']', '{', '}', '|', '<', '>'], ' ', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s;
    }

    /** 返信の編集。書いた本人のみ。編集前の内容は残す。 */
    public function editPost(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);
        $post = self::findPost((int)$args['postId'], (int)$idea['id'], $request);

        if ((int)$post['user_id'] !== Auth::id() || $post['deleted_at'] !== null) {
            Flash::add('error', 'この返信は編集できません。');
            return redirect($response, '/ideas/' . $idea['id']);
        }

        $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
        if ($body === '' || mb_strlen($body) > 10000) {
            Flash::add('error', '本文は1〜10000文字で入力してください。');
            return redirect($response, '/ideas/' . $idea['id'] . '#post-' . $post['id']);
        }
        if ($body === $post['body']) {
            return redirect($response, '/ideas/' . $idea['id'] . '#post-' . $post['id']);
        }

        self::recordPostEdit((int)$post['id'], 'edit', $post['body']);
        Db::query('UPDATE posts SET body = ?, edited_at = ? WHERE id = ?', [$body, Db::now(), $post['id']]);

        Flash::add('success', '返信を編集しました。');
        return redirect($response, '/ideas/' . $idea['id'] . '#post-' . $post['id']);
    }

    /** 返信の削除。書いた本人のみ。論理削除なので内容は残る。 */
    public function deletePost(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);
        $post = self::findPost((int)$args['postId'], (int)$idea['id'], $request);

        if ((int)$post['user_id'] !== Auth::id() || $post['deleted_at'] !== null) {
            Flash::add('error', 'この返信は削除できません。');
            return redirect($response, '/ideas/' . $idea['id']);
        }

        self::recordPostEdit((int)$post['id'], 'delete', $post['body']);
        Db::query(
            'UPDATE posts SET deleted_at = ?, deleted_by = ? WHERE id = ?',
            [Db::now(), Auth::id(), $post['id']]
        );
        Db::query(
            'UPDATE ideas SET posts_count = (SELECT COUNT(*) FROM posts WHERE idea_id = ? AND deleted_at IS NULL) WHERE id = ?',
            [$idea['id'], $idea['id']]
        );

        Flash::add('success', '返信を削除しました。');
        return redirect($response, '/ideas/' . $idea['id']);
    }

    /** スレッドの受付終了 / 再開。起案者と管理者のみ。 */
    public function toggleClosed(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);

        if (!Auth::isAdmin() && (int)$idea['user_id'] !== Auth::id()) {
            Flash::add('error', 'このスレッドの状態は変更できません。');
            return redirect($response, '/ideas/' . $idea['id']);
        }
        if ($idea['status'] === 'hidden') {
            Flash::add('error', '非表示のスレッドは開閉できません。');
            return redirect($response, '/ideas/' . $idea['id']);
        }

        $next = $idea['status'] === 'closed' ? 'open' : 'closed';
        Db::query('UPDATE ideas SET status = ? WHERE id = ?', [$next, $idea['id']]);
        Flash::add('success', $next === 'closed'
            ? 'このスレッドの返信受付を終了しました。'
            : '返信の受付を再開しました。');
        return redirect($response, '/ideas/' . $idea['id']);
    }

    private static function findPost(int $postId, int $ideaId, Request $request): array
    {
        $post = Db::query('SELECT * FROM posts WHERE id = ? AND idea_id = ?', [$postId, $ideaId])->fetch();
        if (!$post) {
            throw new HttpNotFoundException($request);
        }
        return $post;
    }

    private static function recordPostEdit(int $postId, string $action, ?string $beforeBody): void
    {
        Db::query(
            'INSERT INTO post_edits (post_id, editor_id, action, before_body, created_at) VALUES (?, ?, ?, ?, ?)',
            [$postId, Auth::id(), $action, $beforeBody, Db::now()]
        );
    }

    /** 投稿者が自分のスレッドを一覧から下げる / 戻す */
    public function toggleVisibility(Request $request, Response $response, array $args): Response
    {
        $idea = self::findVisible((int)$args['id'], $request);

        if (!IdeaAccess::canToggleVisibility($idea)) {
            Flash::add('error', $idea['status'] === 'hidden'
                ? '管理者が非表示にしたスレッドは、投稿者からは戻せません。'
                : 'このスレッドの表示状態は変更できません。');
            return redirect($response, '/ideas/' . $idea['id']);
        }

        if ($idea['status'] === 'hidden') {
            Db::query("UPDATE ideas SET status = 'open', hidden_by = NULL WHERE id = ?", [$idea['id']]);
            Flash::add('success', 'スレッドを一覧に戻しました。');
        } else {
            Db::query("UPDATE ideas SET status = 'hidden', hidden_by = 'author' WHERE id = ?", [$idea['id']]);
            Flash::add('success', '一覧から下げました。参加した人はこれまでどおり読み書きできます。');
        }
        return redirect($response, '/ideas/' . $idea['id']);
    }

    private static function findVisible(int $id, Request $request): array
    {
        $idea = Db::query('SELECT * FROM ideas WHERE id = ?', [$id])->fetch();
        if (!$idea || !IdeaAccess::canView($idea)) {
            throw new HttpNotFoundException($request);
        }
        return $idea;
    }

    /** @return array<int, string[]> idea_id => タグ名配列 */
    private static function tagsFor(array $ideaIds): array
    {
        if (!$ideaIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ideaIds), '?'));
        $rows = Db::query(
            "SELECT it.idea_id, t.name FROM idea_tags it JOIN tags t ON t.id = it.tag_id
             WHERE it.idea_id IN ({$in}) ORDER BY t.name",
            array_map('intval', $ideaIds)
        )->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['idea_id']][] = $r['name'];
        }
        return $map;
    }

    private static function attachTags(int $ideaId, array $tags): void
    {
        foreach ($tags as $name) {
            $tagId = Db::query('SELECT id FROM tags WHERE name = ?', [$name])->fetchColumn();
            if (!$tagId) {
                Db::query('INSERT IGNORE INTO tags (name) VALUES (?)', [$name]);
                $tagId = Db::query('SELECT id FROM tags WHERE name = ?', [$name])->fetchColumn();
            }
            if ($tagId) {
                Db::query('INSERT IGNORE INTO idea_tags (idea_id, tag_id) VALUES (?, ?)', [$ideaId, $tagId]);
            }
        }
    }
}
