<?php
use App\Support\Csrf;
?>
<div class="auth-box">
  <h1>新しいパスワード</h1>
  <form method="post" action="<?= bp() ?>/reset">
    <?= Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>新しいパスワード (8文字以上)
      <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
    </label>
    <label>確認のためもう一度
      <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
    </label>
    <button type="submit" class="btn btn-primary btn-block">パスワードを変更する</button>
  </form>
</div>
