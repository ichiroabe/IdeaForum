<?php
use App\Support\Csrf;
?>
<div class="form-page">
  <h1>新しいアイディア</h1>
  <form method="post" action="<?= bp() ?>/ideas">
    <?= Csrf::field() ?>
    <label>タイトル
      <input type="text" name="title" required maxlength="200" placeholder="アイディアを一言で">
    </label>
    <label>タグ (カンマ区切り・最大5個)
      <input type="text" name="tags" maxlength="300" placeholder="例: アプリ, 業務改善, 教育">
    </label>
    <label>本文 (Markdown可)
      <textarea name="body" rows="14" required maxlength="20000" placeholder="背景・課題・アイディアの内容など"></textarea>
    </label>
    <div class="hp-field" aria-hidden="true">
      <input type="text" name="website" tabindex="-1" autocomplete="off">
    </div>
    <button type="submit" class="btn btn-primary">投稿する</button>
  </form>
</div>
