<?php
use App\Support\App;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Flash;

$user = Auth::user();
$siteName = (string)App::config('site_name', 'IdeaForum');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(isset($title) && $title !== null ? $title . ' - ' . $siteName : $siteName) ?></title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="/"><?= e($siteName) ?></a>
    <nav class="nav">
      <?php if ($user && $user['status'] === 'active'): ?>
        <a class="btn btn-primary" href="/ideas/new">+ アイディアを出す</a>
      <?php endif; ?>
      <?php if ($user): ?>
        <?php if ($user['role'] === 'admin'): ?><a href="/admin">管理</a><?php endif; ?>
        <span class="username"><?= e($user['display_name']) ?></span>
        <form method="post" action="/logout" class="inline-form">
          <?= Csrf::field() ?>
          <button type="submit" class="link-btn">ログアウト</button>
        </form>
      <?php else: ?>
        <a href="/login">ログイン</a>
        <a class="btn btn-primary" href="/register">新規登録</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container">
  <?php foreach (Flash::pull() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="container">
    <p><?= e($siteName) ?> — アイディアを出し合い、openManidocへ。</p>
  </div>
</footer>
</body>
</html>
