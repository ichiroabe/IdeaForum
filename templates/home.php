<?php /** @var array $ideas, $tagsByIdea, $allTags */ ?>
<div class="page-head">
  <h1>アイディア一覧</h1>
  <form method="get" action="<?= bp() ?>/" class="search-form">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="キーワード検索">
    <?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?= e($tag) ?>"><?php endif; ?>
    <button type="submit" class="btn">検索</button>
  </form>
</div>

<?php if ($allTags): ?>
<div class="tag-cloud">
  <?php if ($tag !== '' || $search !== ''): ?><a class="tag-chip tag-clear" href="<?= bp() ?>/">すべて表示</a><?php endif; ?>
  <?php foreach ($allTags as $t): ?>
    <a class="tag-chip <?= $t['name'] === $tag ? 'active' : '' ?>" href="<?= bp() ?>/?tag=<?= urlencode($t['name']) ?>">
      <?= e($t['name']) ?> <span class="tag-count"><?= (int)$t['cnt'] ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$ideas): ?>
  <p class="empty">まだアイディアがありません。最初のアイディアを投稿してみましょう。</p>
<?php else: ?>
<ul class="idea-list">
  <?php foreach ($ideas as $i): ?>
  <li class="idea-card <?= $i['status'] === 'hidden' ? 'is-hidden' : '' ?>">
    <div class="idea-main">
      <a class="idea-title" href="<?= bp() ?>/ideas/<?= (int)$i['id'] ?>"><?= e($i['title']) ?></a>
      <div class="idea-meta">
        <span><?= e($i['display_name']) ?></span>
        <span><?= e(fmt_date($i['updated_at'])) ?></span>
        <?php if ($i['status'] === 'closed'): ?><span class="badge">終了</span><?php endif; ?>
        <?php if ($i['status'] === 'hidden'): ?><span class="badge badge-danger">非表示</span><?php endif; ?>
      </div>
      <?php if (!empty($tagsByIdea[(int)$i['id']])): ?>
      <div class="idea-tags">
        <?php foreach ($tagsByIdea[(int)$i['id']] as $tn): ?>
          <a class="tag-chip small" href="<?= bp() ?>/?tag=<?= urlencode($tn) ?>"><?= e($tn) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="idea-replies" title="返信数"><?= (int)$i['posts_count'] ?></div>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($totalPages > 1): ?>
<nav class="pagination">
  <?php
  $qs = fn(int $p) => '/?' . http_build_query(array_filter(['tag' => $tag, 'q' => $search, 'page' => $p]));
  ?>
  <?php if ($page > 1): ?><a href="<?= e($qs($page - 1)) ?>">« 前へ</a><?php endif; ?>
  <span><?= $page ?> / <?= $totalPages ?></span>
  <?php if ($page < $totalPages): ?><a href="<?= e($qs($page + 1)) ?>">次へ »</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
