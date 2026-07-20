<?php
use App\Support\App;
use App\Support\Auth;
use App\Support\Avatar;
use App\Support\Unread;
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
<link rel="stylesheet" href="<?= bp() ?>/assets/style.css">
</head>
<body>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= bp() ?>/"><?= e($siteName) ?></a>
    <nav class="nav">
      <?php if ($user && $user['status'] === 'active'): ?>
        <a class="btn btn-primary" href="<?= bp() ?>/ideas/new">+ アイディアを出す</a>
      <?php endif; ?>
      <?php if ($user): ?>
        <?php $newCount = Unread::count(); ?>
        <?php if ($newCount > 0): ?>
          <a href="<?= bp() ?>/" title="自分が関わったスレッドに更新があります">新着<span class="nav-count"><?= $newCount ?></span></a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
          <?php $openReports = Unread::openReports(); ?>
          <a href="<?= bp() ?>/admin">管理<?php if ($openReports > 0): ?><span class="nav-count"><?= $openReports ?></span><?php endif; ?></a>
        <?php endif; ?>
        <a class="username" href="<?= bp() ?>/settings" title="表示の設定">
          <?= Avatar::html($user, 'sm') ?><?= e($user['display_name']) ?>
        </a>
        <form method="post" action="<?= bp() ?>/logout" class="inline-form">
          <?= Csrf::field() ?>
          <button type="submit" class="link-btn">ログアウト</button>
        </form>
      <?php else: ?>
        <a href="<?= bp() ?>/login">ログイン</a>
        <a class="btn btn-primary" href="<?= bp() ?>/register">新規登録</a>
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
