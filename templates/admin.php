<?php
use App\Support\Csrf;
?>
<h1>管理</h1>

<section>
  <h2>未対応の通報 (<?= count($reports) ?>)</h2>
  <?php if (!$reports): ?>
    <p class="note">未対応の通報はありません。</p>
  <?php else: ?>
  <table class="admin-table">
    <thead>
      <tr><th>日時</th><th>対象</th><th>内容</th><th>理由</th><th>通報者</th><th>操作</th></tr>
    </thead>
    <tbody>
      <?php foreach ($reports as $r): ?>
      <tr>
        <td><?= e(fmt_date($r['created_at'])) ?></td>
        <td>
          <?= $r['target_type'] === 'idea' ? 'アイディア' : '返信' ?>
          <?php if ($r['idea_id']): ?><a href="<?= bp() ?>/ideas/<?= (int)$r['idea_id'] ?>">#<?= (int)$r['target_id'] ?></a><?php endif; ?>
        </td>
        <td class="preview"><?= e((string)$r['target_preview']) ?></td>
        <td><?= e($r['reason']) ?></td>
        <td><?= e($r['reporter_name']) ?></td>
        <td class="actions">
          <form method="post" action="<?= bp() ?>/admin/toggle-visibility" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="target_type" value="<?= e($r['target_type']) ?>">
            <input type="hidden" name="target_id" value="<?= (int)$r['target_id'] ?>">
            <button type="submit" class="btn btn-warn"><?= $r['target_status'] === 'hidden' ? '再表示' : '非表示' ?></button>
          </form>
          <form method="post" action="<?= bp() ?>/admin/resolve-report" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn">対応済み</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section>
  <h2>ユーザー (直近100件)</h2>
  <table class="admin-table">
    <thead>
      <tr><th>ID</th><th>表示名</th><th>メール</th><th>状態</th><th>登録日</th><th>最終ログイン</th><th>操作</th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><?= e($u['display_name']) ?><?= $u['role'] === 'admin' ? ' 👑' : '' ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e($u['status']) ?></td>
        <td><?= e(fmt_date($u['created_at'])) ?></td>
        <td><?= e(fmt_date($u['last_login_at'])) ?></td>
        <td>
          <?php if ($u['role'] !== 'admin'): ?>
          <form method="post" action="<?= bp() ?>/admin/toggle-ban" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn btn-warn"><?= $u['status'] === 'banned' ? '復帰' : '停止' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
