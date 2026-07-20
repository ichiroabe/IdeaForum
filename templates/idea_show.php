<?php
use App\Support\Auth;
use App\Support\Avatar;
use App\Support\Csrf;
use App\Support\IdeaAccess;
use App\Support\Text;

$user = Auth::user();
$canPost = $user && $user['status'] === 'active';
$canToggle = IdeaAccess::canToggleVisibility($idea);
$isMyIdea = $user && (int)$idea['user_id'] === (int)$user['id'];
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
        <button type="submit" class="btn btn-warn"
                title="管理者として非表示にします。参加者にも見えなくなります。">
          <?= $idea['status'] === 'hidden' ? '再表示(管理)' : '非表示(管理)' ?>
        </button>
      </form>
      <?php elseif ($isMyIdea && $canToggle): ?>
      <form method="post" action="<?= bp() ?>/ideas/<?= (int)$idea['id'] ?>/visibility" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="btn"
                title="一覧から下げます。参加した人はこれまでどおり読み書きできます。">
          <?= $idea['status'] === 'hidden' ? '一覧に戻す' : '一覧から下げる' ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="idea-meta">
    <span class="avatar-with-name">
      <?= Avatar::html([
            'id' => $idea['user_id'],
            'display_name' => $idea['author_name'] ?? '',
            'avatar_emoji' => $idea['author_emoji'] ?? null,
            'avatar_color' => $idea['author_color'] ?? null,
          ], 'md') ?><?= e($idea['author_name'] ?? '') ?>
    </span>
    <span><?= e(fmt_date($idea['created_at'])) ?></span>
    <?php if ($idea['status'] === 'closed'): ?><span class="badge">終了</span><?php endif; ?>
    <?php if ($idea['status'] === 'hidden'): ?>
      <span class="badge badge-danger">
        <?= ($idea['hidden_by'] ?? 'admin') === 'author' ? '一覧から非表示' : '管理者が非表示' ?>
      </span>
    <?php endif; ?>
  </div>
  <?php if ($idea['status'] === 'hidden' && ($idea['hidden_by'] ?? 'admin') === 'author'): ?>
    <p class="note">このスレッドは一覧に出ていません。起案者と、返信したことがある人だけが読み書きできます。</p>
  <?php endif; ?>
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

<details class="board-section fold" id="fold-board" open>
  <summary class="fold-summary">
    <span class="fold-title">付箋ボード</span>
    <span class="fold-hint">クリックで開閉</span>
  </summary>
  <div class="board-head">
    <?php if ($canPost): ?>
      <button type="button" class="btn btn-primary board-add">+ 付箋を追加</button>
    <?php endif; ?>
    <span class="board-status"></span>
  </div>
  <div class="board-legend"></div>
  <?php if (!$canPost): ?>
    <p class="note">付箋の追加・編集には <a href="<?= bp() ?>/login">ログイン</a> が必要です。</p>
  <?php else: ?>
    <p class="note">
      ドラッグで移動。「連」を押してから別の付箋をクリックすると線でつながり、線をクリックで削除できます。
      他の人が作った付箋を変更・削除するときは理由の入力が必要で、「履」からその付箋の履歴を確認できます。
    </p>
  <?php endif; ?>
  <div class="board-panel" hidden></div>
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
</details>

<details class="posts fold" id="fold-posts" open>
  <summary class="fold-summary">
    <span class="fold-title">返信 (<?= count($posts) ?>)</span>
    <span class="fold-hint">クリックで開閉</span>
  </summary>
  <?php foreach ($posts as $p): ?>
    <?php if ($p['status'] === 'hidden' && !Auth::isAdmin()): ?>
      <div class="post post-hidden" id="post-<?= (int)$p['id'] ?>"><p class="note">この投稿は非表示になっています。</p></div>
      <?php continue; ?>
    <?php endif; ?>
    <div class="post <?= $p['status'] === 'hidden' ? 'is-hidden' : '' ?>" id="post-<?= (int)$p['id'] ?>">
      <div class="post-meta">
        <?= Avatar::html($p, 'md') ?>
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
      <div class="md-body is-clampable"><?= Text::markdown($p['body']) ?></div>
    </div>
  <?php endforeach; ?>

  <?php /* 判定は IdeaAccess に一本化する。ここで status を直接見ると
           一覧から下げたスレッドで参加者に返信欄が出なくなる。 */ ?>
  <?php if (IdeaAccess::canReply($idea)): ?>
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
</details>

<script src="<?= bp() ?>/assets/fold.js" defer></script>
<script src="<?= bp() ?>/assets/board.js" defer></script>
