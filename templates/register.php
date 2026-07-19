<?php
use App\Support\Csrf;
use App\Support\Turnstile;
?>
<div class="auth-box">
  <h1>新規登録</h1>
  <p class="note">登録にはメールアドレスの確認が必要です。確認メールのリンクを開くと投稿できるようになります。</p>
  <form method="post" action="/register">
    <?= Csrf::field() ?>
    <label>メールアドレス
      <input type="email" name="email" required maxlength="255" autocomplete="email">
    </label>
    <label>表示名
      <input type="text" name="display_name" required maxlength="50" autocomplete="nickname">
    </label>
    <label>パスワード (8文字以上)
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>
    <div class="hp-field" aria-hidden="true">
      <label>このフィールドは空のままにしてください
        <input type="text" name="website" tabindex="-1" autocomplete="off">
      </label>
    </div>
    <?= Turnstile::widget() ?>
    <button type="submit" class="btn btn-primary btn-block">登録する</button>
  </form>
  <p class="auth-alt">アカウントをお持ちの方は <a href="/login">ログイン</a></p>
</div>
