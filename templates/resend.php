<?php
use App\Support\Csrf;
?>
<div class="auth-box">
  <h1>確認メールの再送</h1>
  <p class="note">登録済みで未確認のメールアドレスに、確認メールを再送します。</p>
  <form method="post" action="/resend">
    <?= Csrf::field() ?>
    <label>メールアドレス
      <input type="email" name="email" required maxlength="255" value="<?= e($email ?? '') ?>">
    </label>
    <button type="submit" class="btn btn-primary btn-block">再送する</button>
  </form>
</div>
