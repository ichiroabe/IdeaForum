<?php
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Text;

$user = Auth::user();
$canPost = $user && $user['status'] === 'active';
$currentPath = '/ideas/' . (int)$idea['id'];
?>
<article class="idea-detail">
  <div class="idea-detail-head">
    <h1><?= e($idea['title']) ?></h1>
    <div class="idea-actions">
      <a class="btn" href="<?= bp() ?>/ideas/<?= (int)$idea['id'] ?>/export.md" title="openManidocに取り込めるMarkdownをダウンロード">MD出力</a>
      <?php if (Auth::isAdmin()): ?>
      <form method="post" action="<?= bp() ?>/admin/toggle-visibility" class="inline-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="target_type" value="idea">
        <input type="hidden" name="target_id" value="<?= (int)$idea['id'] ?>">
        <input type="hidden" name="back" value="<?= e($currentPath) ?>">
        <button type="submit" class="btn btn-warn"><?= $idea['status'] === 'hidden' ? '再表示' : '非表示' ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="idea-meta">
    <span><?= e($idea['author_name'] ?? '') ?></span>
    <span><?= e(fmt_date($idea['created_at'])) ?></span>
    <?php if ($idea['status'] === 'closed'): ?><span class="badge">終了</span><?php endif; ?>
    <?php if ($idea['status'] === 'hidden'): ?><span class="badge badge-danger">非表示</span><?php endif; ?>
  </div>
  <?php if ($tags): ?>
  <div class="idea-tags">
    <?php foreach ($tags as $tn): ?>
      <a class="tag-chip small" href="<?= bp() ?>/?tag=<?= urlencode($tn) ?>"><?= e($tn) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="md-body"><?= Text::markdown($idea['body']) ?></div>
  <?php if ($canPost): ?>
  <details class="report-box">
    <summary>このアイディアを通報</summary>
    <form method="post" action="<?= bp() ?>/report">
      <?= Csrf::field() ?>
      <input type="hidden" name="target_type" value="idea">
      <input type="hidden" name="target_id" value="<?= (int)$idea['id'] ?>">
      <input type="text" name="reason" required maxlength="500" placeholder="理由">
      <button type="submit" class="btn">通報する</button>
    </form>
  </details>
  <?php endif; ?>
</article>

<section class="board-section">
  <div class="board-head">
    <h2>付箋ボード</h2>
    <?php if ($canPost): ?>
      <button type="button" class="btn btn-primary board-add">+ 付箋を追加</button>
    <?php endif; ?>
    <span class="board-status"></span>
  </div>
  <?php if (!$canPost): ?>
    <p class="note">付箋の追加・編集には <a href="<?= bp() ?>/login">ログイン</a> が必要です。</p>
  <?php else: ?>
    <p class="note">付箋はドラッグで移動できます。「連」を押してから別の付箋をクリックすると線でつながります。線をクリックすると削除できます。</p>
  <?php endif; ?>
  <div id="board" class="board"
       data-idea-id="<?= (int)$idea['id'] ?>"
       data-base="<?= e(bp()) ?>"
       data-csrf="<?= e(Csrf::token()) ?>">
    <div class="board-scroll">
      <div class="board-canvas">
        <svg class="board-lines" xmlns="http://www.w3.org/2000/svg"></svg>
      </div>
    </div>
  </div>
</section>

<section class="posts">
  <h2>返信 (<?= count($posts) ?>)</h2>
  <?php foreach ($posts as $p): ?>
    <?php if ($p['status'] === 'hidden' && !Auth::isAdmin()): ?>
      <div class="post post-hidden" id="post-<?= (int)$p['id'] ?>"><p class="note">この投稿は非表示になっています。</p></div>
      <?php continue; ?>
    <?php endif; ?>
    <div class="post <?= $p['status'] === 'hidden' ? 'is-hidden' : '' ?>" id="post-<?= (int)$p['id'] ?>">
      <div class="post-meta">
        <strong><?= e($p['display_name']) ?></strong>
        <span><?= e(fmt_date($p['created_at'])) ?></span>
        <?php if ($p['status'] === 'hidden'): ?><span class="badge badge-danger">非表示</span><?php endif; ?>
        <span class="post-actions">
          <?php if ($canPost): ?>
          <button type="button" class="link-btn" data-note-from-post="<?= (int)$p['id'] ?>"
                  data-note-body="<?= e(mb_substr($p['body'], 0, 500)) ?>"
                  title="この返信を付箋にしてボードへ追加">付箋にする</button>
          <details class="report-box small">
            <summary>通報</summary>
            <form method="post" action="<?= bp() ?>/report">
              <?= Csrf::field() ?>
              <input type="hidden" name="target_type" value="post">
              <input type="hidden" name="target_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="back" value="<?= e($currentPath) ?>">
              <input type="text" name="reason" required maxlength="500" placeholder="理由">
              <button type="submit" class="btn">送信</button>
            </form>
          </details>
          <?php endif; ?>
          <?php if (Auth::isAdmin()): ?>
          <form method="post" action="<?= bp() ?>/admin/toggle-visibility" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="target_type" value="post">
            <input type="hidden" name="target_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="back" value="<?= e($currentPath) ?>">
            <button type="submit" class="link-btn"><?= $p['status'] === 'hidden' ? '再表示' : '非表示' ?></button>
          </form>
          <?php endif; ?>
        </span>
      </div>
      <div class="md-body"><?= Text::markdown($p['body']) ?></div>
    </div>
  <?php endforeach; ?>

  <?php if ($canPost && $idea['status'] === 'open'): ?>
  <div class="reply-form">
    <h3>返信する</h3>
    <form method="post" action="<?= bp() ?>/ideas/<?= (int)$idea['id'] ?>/reply">
      <?= Csrf::field() ?>
      <textarea name="body" rows="6" required maxlength="10000" placeholder="このアイディアへの意見・改善案・派生アイディアなど (Markdown可)"></textarea>
      <div class="hp-field" aria-hidden="true">
        <input type="text" name="website" tabindex="-1" autocomplete="off">
      </div>
      <button type="submit" class="btn btn-primary">返信を投稿</button>
    </form>
  </div>
  <?php elseif (!$user): ?>
  <p class="note">返信するには <a href="<?= bp() ?>/login">ログイン</a> してください。</p>
  <?php elseif ($idea['status'] === 'closed'): ?>
  <p class="note">このスレッドは閉じられています。</p>
  <?php endif; ?>
</section>

<script src="<?= bp() ?>/assets/board.js" defer></script>
