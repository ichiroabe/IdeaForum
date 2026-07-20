<?php
use App\Support\Csrf;
?>
<div class="auth-box">
  <h1>パスワードの再設定</h1>
  <p class="note">ご登録のメールアドレスに、再設定用のリンクをお送りします。リンクの有効期限は1時間です。</p>
  <form method="post" action="<?= bp() ?>/forgot">
    <?= Csrf::field() ?>
    <label>メールアドレス
      <input type="email" name="email" required maxlength="255" autocomplete="email" autofocus>
    </label>
    <button type="submit" class="btn btn-primary btn-block">再設定メールを送る</button>
  </form>
  <p class="auth-alt"><a href="<?= bp() ?>/login">ログイン画面に戻る</a></p>
</div>
