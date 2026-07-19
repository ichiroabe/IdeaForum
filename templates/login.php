<?php
use App\Support\Csrf;
?>
<div class="auth-box">
  <h1>ログイン</h1>
  <form method="post" action="<?= bp() ?>/login">
    <?= Csrf::field() ?>
    <label>メールアドレス
      <input type="email" name="email" required maxlength="255" autocomplete="email">
    </label>
    <label>パスワード
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn btn-primary btn-block">ログイン</button>
  </form>
  <p class="auth-alt">はじめての方は <a href="<?= bp() ?>/register">新規登録</a></p>
  <p class="auth-alt"><a href="<?= bp() ?>/resend">確認メールが届かない場合(再送)</a></p>
</div>
