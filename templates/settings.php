<?php
use App\Support\Avatar;
use App\Support\Csrf;
?>
<div class="form-page">
  <h1>表示の設定</h1>

  <div class="settings-preview">
    <span class="settings-preview-label">いまの見え方</span>
    <?= Avatar::html($user, 'lg') ?>
    <strong id="preview-name"><?= e($user['display_name']) ?></strong>
  </div>

  <form method="post" action="<?= bp() ?>/settings">
    <?= Csrf::field() ?>

    <label>表示名
      <input type="text" name="display_name" required maxlength="50"
             value="<?= e($user['display_name']) ?>">
    </label>

    <fieldset class="picker">
      <legend>アバターの絵文字</legend>
      <p class="note">画像のアップロードはありません。選ばない場合は表示名の頭文字が出ます。</p>
      <div class="picker-grid">
        <label class="picker-item picker-none">
          <input type="radio" name="avatar_emoji" value=""
                 <?= !Avatar::isValidEmoji($user['avatar_emoji']) ? 'checked' : '' ?>>
          <span>頭文字</span>
        </label>
        <?php foreach (Avatar::EMOJI as $emoji): ?>
          <label class="picker-item">
            <input type="radio" name="avatar_emoji" value="<?= e($emoji) ?>"
                   <?= ($user['avatar_emoji'] ?? '') === $emoji ? 'checked' : '' ?>>
            <span><?= e($emoji) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset class="picker">
      <legend>背景の色</legend>
      <p class="note">選ばない場合は自動で割り当てられます。</p>
      <div class="picker-grid">
        <label class="picker-item picker-none">
          <input type="radio" name="avatar_color" value=""
                 <?= !Avatar::isValidColor($user['avatar_color']) ? 'checked' : '' ?>>
          <span>自動</span>
        </label>
        <?php foreach (Avatar::COLORS as $key => $hex): ?>
          <label class="picker-item">
            <input type="radio" name="avatar_color" value="<?= e($key) ?>"
                   <?= ($user['avatar_color'] ?? '') === $key ? 'checked' : '' ?>>
            <span class="picker-color" style="background: <?= e($hex) ?>"></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <button type="submit" class="btn btn-primary">保存する</button>
  </form>
</div>

<div class="form-page my-ideas">
  <h2>自分が立ち上げたアイディア (<?= count($myIdeas) ?>)</h2>
  <?php if (!$myIdeas): ?>
    <p class="note">まだありません。<a href="<?= bp() ?>/ideas/new">最初のアイディアを出す</a></p>
  <?php else: ?>
    <ul class="my-idea-list">
      <?php foreach ($myIdeas as $mi): ?>
        <li class="<?= $mi['status'] === 'hidden' ? 'is-hidden' : '' ?>">
          <a href="<?= bp() ?>/ideas/<?= (int)$mi['id'] ?>"><?= e($mi['title']) ?></a>
          <span class="my-idea-meta">
            返信 <?= (int)$mi['posts_count'] ?>
            ・<?= e(fmt_date($mi['updated_at'])) ?>
            <?php if ($mi['status'] === 'closed'): ?><span class="badge">終了</span><?php endif; ?>
            <?php if ($mi['status'] === 'hidden'): ?>
              <span class="badge badge-danger">
                <?= ($mi['hidden_by'] ?? 'admin') === 'author' ? '一覧から非表示' : '管理者が非表示' ?>
              </span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<script src="<?= bp() ?>/assets/settings.js" defer></script>
