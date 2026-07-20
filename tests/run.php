<?php
declare(strict_types=1);

/**
 * 依存を増やさない最小のテスト。
 *
 *   php tests/run.php [ベースURL]
 *
 * 動いているサーバーにHTTPで問い合わせ、権限まわりの規則を確かめる。
 * Playwrightのようなブラウザ自動化は入れていない。数百MBの依存と
 * Node環境が必要になり、このプロジェクトの方針(依存を最小限に)と
 * 合わないため。守りたいのは画面の見た目ではなく、サーバー側の規則。
 *
 * 前提: ローカル開発サーバーとDBが動いていること。
 *       テスト用の利用者と投稿を自動で作り、終了時に片付ける。
 */

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config/config.php がありません。\n");
    exit(1);
}
$config = require $configPath;
$db = $config['db'];

$pdo = new PDO(
    "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// --- 小さなテスト基盤 --------------------------------------------------------

$passed = 0;
$failed = [];

function check(string $name, mixed $actual, mixed $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        printf("  ok   %s\n", $name);
    } else {
        $failed[] = $name;
        printf("  FAIL %s (期待 %s / 実際 %s)\n", $name, json_encode($expected), json_encode($actual));
    }
}

function section(string $title): void
{
    printf("\n%s\n", $title);
}

/** クッキーを保持したままHTTPを叩く小さなクライアント */
final class Client
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'ifcookie');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status:int, body:string} */
    public function request(string $method, string $path, array $form = [], array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($form) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** ページからCSRFトークンを取り出す */
    public function token(string $path): string
    {
        $res = $this->get($path);
        return preg_match('/name="_csrf" value="([^"]+)"/', $res['body'], $m) ? $m[1] : '';
    }

    public function login(string $email, string $password): bool
    {
        $this->request('POST', '/login', [
            '_csrf'    => $this->token('/login'),
            'email'    => $email,
            'password' => $password,
        ]);
        return str_contains($this->get('/')['body'], 'ログアウト');
    }
}

// --- 検証用のデータを用意 ----------------------------------------------------

// 前回が途中で落ちていた場合に備えて、古い検証用データを先に片付ける。
// 残っていると利用者一覧が汚れ、次の実行にも影響するため。
$stale = $pdo->query("SELECT id FROM users WHERE email LIKE 'itest\\_%@example.test'")->fetchAll();
if ($stale) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $stale));
    $pdo->exec("DELETE FROM post_edits  WHERE editor_id   IN ({$ids})");
    $pdo->exec("DELETE FROM note_events WHERE actor_id    IN ({$ids})");
    $pdo->exec("DELETE FROM reports     WHERE reporter_id IN ({$ids})");
    $pdo->exec("DELETE FROM ideas       WHERE user_id     IN ({$ids})");
    $pdo->exec("DELETE FROM users       WHERE id          IN ({$ids})");
    printf("前回の残骸 %d 件を片付けました。\n", count($stale));
}

$hash = password_hash('testpw-12345', PASSWORD_DEFAULT);
$suffix = 'itest_' . bin2hex(random_bytes(4));
$users = [];
foreach (['author', 'member', 'stranger', 'admin'] as $role) {
    $email = "{$suffix}_{$role}@example.test";
    $pdo->prepare(
        "INSERT INTO users (email, password_hash, display_name, role, status, created_at)
         VALUES (?, ?, ?, ?, 'active', NOW())"
    )->execute([$email, $hash, $role, $role === 'admin' ? 'admin' : 'user']);
    $users[$role] = ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
}

$pdo->prepare(
    "INSERT INTO ideas (user_id, title, body, status, posts_count, created_at, updated_at)
     VALUES (?, ?, 'test body', 'open', 0, NOW(), NOW())"
)->execute([$users['author']['id'], "[test] {$suffix}"]);
$ideaId = (int)$pdo->lastInsertId();

// member は返信済み = 参加者
$pdo->prepare("INSERT INTO posts (idea_id, user_id, body, created_at) VALUES (?, ?, 'test reply', NOW())")
    ->execute([$ideaId, $users['member']['id']]);

$cleanup = function () use ($pdo, $ideaId, $users) {
    // 履歴系は利用者への外部キーを持つので先に消す。
    // (本番では利用者を削除せず停止するだけなので、この順序が要るのは検証時のみ)
    $ids = implode(',', array_map(fn($u) => (int)$u['id'], $users));
    $pdo->exec("DELETE FROM post_edits  WHERE editor_id IN ({$ids})");
    $pdo->exec("DELETE FROM note_events WHERE actor_id  IN ({$ids})");
    $pdo->exec("DELETE FROM reports     WHERE reporter_id IN ({$ids})");
    $pdo->prepare('DELETE FROM ideas WHERE id = ?')->execute([$ideaId]);
    $pdo->exec("DELETE FROM users WHERE id IN ({$ids})");
};

// 途中で落ちても後片付けする
register_shutdown_function($cleanup);

$clients = [];
foreach ($users as $role => $u) {
    $c = new Client($base);
    if (!$c->login($u['email'], 'testpw-12345')) {
        fwrite(STDERR, "テスト利用者 {$role} でログインできませんでした。サーバーは動いていますか?\n");
        exit(1);
    }
    $clients[$role] = $c;
}
$anon = new Client($base);

$setStatus = function (?string $status, ?string $hiddenBy) use ($pdo, $ideaId) {
    $pdo->prepare('UPDATE ideas SET status = ?, hidden_by = ? WHERE id = ?')
        ->execute([$status ?? 'open', $hiddenBy, $ideaId]);
};

// --- ここから検証 ------------------------------------------------------------

printf("対象: %s (idea #%d)\n", $base, $ideaId);

section('公開中のスレッドは誰でも読める');
$setStatus('open', null);
check('未ログイン', $anon->get("/ideas/{$ideaId}")['status'], 200);
foreach (['author', 'member', 'stranger', 'admin'] as $r) {
    check($r, $clients[$r]->get("/ideas/{$ideaId}")['status'], 200);
}

section('起案者が下げたスレッドは、参加者と管理者だけが読める');
$setStatus('hidden', 'author');
check('未ログインは読めない', $anon->get("/ideas/{$ideaId}")['status'], 404);
check('起案者は読める', $clients['author']->get("/ideas/{$ideaId}")['status'], 200);
check('返信した人は読める', $clients['member']->get("/ideas/{$ideaId}")['status'], 200);
check('無関係な人は読めない', $clients['stranger']->get("/ideas/{$ideaId}")['status'], 404);
check('管理者は読める', $clients['admin']->get("/ideas/{$ideaId}")['status'], 200);
check('付箋APIも参加者は通る', $clients['member']->get("/ideas/{$ideaId}/notes")['status'], 200);
check('付箋APIは無関係な人を拒む', $clients['stranger']->get("/ideas/{$ideaId}/notes")['status'], 404);
check('MD出力も無関係な人を拒む', $clients['stranger']->get("/ideas/{$ideaId}/export.md")['status'], 404);

section('管理者が非表示にしたら、起案者も含めて誰も読めない');
$setStatus('hidden', 'admin');
check('起案者でも読めない', $clients['author']->get("/ideas/{$ideaId}")['status'], 404);
check('参加者でも読めない', $clients['member']->get("/ideas/{$ideaId}")['status'], 404);
check('管理者だけ読める', $clients['admin']->get("/ideas/{$ideaId}")['status'], 200);

section('起案者は管理者の非表示を解除できない');
$c = $clients['author'];
$c->request('POST', "/ideas/{$ideaId}/visibility", ['_csrf' => $c->token('/')]);
$row = $pdo->query("SELECT status, hidden_by FROM ideas WHERE id = {$ideaId}")->fetch();
check('状態が変わらない', $row['status'] . '/' . $row['hidden_by'], 'hidden/admin');

section('CSRFトークンが無い要求は拒まれる');
$setStatus('open', null);
$res = $clients['member']->request('POST', "/ideas/{$ideaId}/reply", ['body' => 'no token']);
check('返信は弾かれる', $res['status'], 302);
$before = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE idea_id = {$ideaId}")->fetchColumn();
$res = $clients['member']->request(
    'POST',
    "/ideas/{$ideaId}/notes",
    [],
    ['Content-Type: application/json', 'Accept: application/json']
);
check('付箋APIは419を返す', $res['status'], 419);
check('付箋は増えていない', (int)$pdo->query("SELECT COUNT(*) FROM notes WHERE idea_id = {$ideaId}")->fetchColumn(), 0);

section('他人の返信は編集も削除もできない');
$postId = (int)$pdo->query("SELECT id FROM posts WHERE idea_id = {$ideaId} LIMIT 1")->fetchColumn();
$c = $clients['stranger'];
$c->request('POST', "/ideas/{$ideaId}/posts/{$postId}/edit", ['_csrf' => $c->token('/'), 'body' => 'hijacked']);
check('本文が変わらない', $pdo->query("SELECT body FROM posts WHERE id = {$postId}")->fetchColumn(), 'test reply');
$c->request('POST', "/ideas/{$ideaId}/posts/{$postId}/delete", ['_csrf' => $c->token('/')]);
check('削除されない', $pdo->query("SELECT deleted_at FROM posts WHERE id = {$postId}")->fetchColumn(), null);

section('本人は自分の返信を編集できる');
$c = $clients['member'];
$c->request('POST', "/ideas/{$ideaId}/posts/{$postId}/edit", ['_csrf' => $c->token("/ideas/{$ideaId}"), 'body' => 'edited body']);
check('本文が変わる', $pdo->query("SELECT body FROM posts WHERE id = {$postId}")->fetchColumn(), 'edited body');
check('編集前が残る', $pdo->query("SELECT before_body FROM post_edits WHERE post_id = {$postId}")->fetchColumn(), 'test reply');

section('未ログインでは書き込めない');
check('返信フォームのPOST', $anon->request('POST', "/ideas/{$ideaId}/reply", ['body' => 'x'])['status'], 302);
check('設定画面', $anon->get('/settings')['status'], 302);
// 管理画面は存在を明かさないよう、未ログインでもリダイレクトせず403を返す
check('管理画面', $anon->get('/admin')['status'], 403);
check('一般利用者の管理画面', $clients['member']->get('/admin')['status'], 403);

// --- 結果 --------------------------------------------------------------------

printf("\n----\n%d 件成功", $passed);
if ($failed) {
    printf(" / %d 件失敗\n", count($failed));
    foreach ($failed as $f) {
        printf("  - %s\n", $f);
    }
    exit(1);
}
printf(" / 失敗なし\n");
exit(0);
