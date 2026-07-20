<?php
use App\Controller\NoteController;
use App\Support\App;
use App\Support\Avatar;

// 色や上限は実装から読む。ヘルプだけが古くなるのを避けるため。
$colors = NoteController::COLORS;
$limits = (array)App::config('limits', []);
?>
<div class="help-page">
  <div class="page-head">
    <div>
      <h1>使い方のヘルプ</h1>
      <p class="help-lead">IdeaForum でできることを一通り説明します。</p>
    </div>
  </div>

  <section class="help-card">
    <h2>💡 IdeaForum とは</h2>
    <p>
      アイディアを出し合い、意見を交わしながら育てるための掲示板です。
      話し合いの内容は <strong>Markdown</strong> として持ち出せるので、
      <strong>openManidoc</strong> など手元の道具へそのまま取り込めます。
    </p>
    <p>
      ふつうの掲示板と違うのは、スレッドごとに <strong>付箋ボード</strong> が付いていることです。
      文章のやりとりで出てきた話を付箋にして並べ替え、線でつなぐことで、
      議論を図として整理できます。
    </p>
  </section>

  <section class="help-card">
    <h2>🔰 はじめての方へ</h2>
    <ol>
      <li>
        <strong>新規登録</strong><br>
        メールアドレス・パスワード(8文字以上)・表示名を入力します。
      </li>
      <li>
        <strong>メールの確認</strong><br>
        届いたメールのリンクを開くと登録が完了します。リンクの有効期限は24時間です。
        <br><span class="help-note">確認が済むまで、投稿・返信・付箋の編集はできません。閲覧はできます。</span>
      </li>
      <li>
        <strong>投稿してみる</strong><br>
        確認が済むと、ヘッダーに「+ アイディアを出す」が現れます。
      </li>
    </ol>

    <h3>うまくいかないとき</h3>
    <ul>
      <li><strong>確認メールが届かない</strong> … ログイン画面の「確認メールが届かない場合(再送)」から送り直せます。迷惑メールフォルダもご確認ください。</li>
      <li><strong>パスワードを忘れた</strong> … ログイン画面の「パスワードを忘れた場合」から再設定できます。再設定用リンクの有効期限は1時間です。</li>
    </ul>
  </section>

  <section class="help-card">
    <h2>📝 アイディアを探す・投稿する</h2>

    <h3>探す</h3>
    <ul>
      <li><strong>検索</strong> … トップの検索窓から探します。タイトルと本文だけでなく、<strong>返信と付箋の中身も対象</strong>です。</li>
      <li><strong>タグで絞る</strong> … タグをクリックすると、そのタグが付いたものだけになります。</li>
      <li><strong>並び順</strong> … 更新が新しい順に固定です。返信や付箋が動くと上に上がります。</li>
      <li><strong>新着</strong> … 自分が立てた、または返信したスレッドに動きがあると、一覧に「新着」印が付き、ヘッダーにも件数が出ます。そのスレッドを開くと消えます。</li>
    </ul>

    <h3>投稿する</h3>
    <ul>
      <li><strong>タイトル</strong> … 200文字まで。</li>
      <li><strong>タグ</strong> … カンマ区切りで最大5個。</li>
      <li><strong>本文</strong> … Markdown が使えます。10000文字まで。</li>
    </ul>
    <p class="help-note">
      連続投稿の制限があります。1日あたり
      <?= (int)($limits['ideas_per_user_per_day'] ?? 10) ?>件のアイディアと
      <?= (int)($limits['posts_per_user_per_day'] ?? 60) ?>件の返信まで、
      投稿の間隔は<?= (int)($limits['post_cooldown_seconds'] ?? 20) ?>秒あけてください。
    </p>
  </section>

  <section class="help-card">
    <h2>💬 返信でやりとりする</h2>
    <ul>
      <li><strong>返信する</strong> … スレッド下部のフォームから投稿します。Markdown が使えます。</li>
      <li><strong>自分の返信を直す</strong> … 自分の返信には「編集」「削除」が出ます。編集すると「(編集済み)」と表示され、<strong>編集前の内容も記録に残ります</strong>。削除しても「この返信は削除されました」という跡が残り、話の流れが分かるようにしています。</li>
      <li><strong>付箋にする</strong> … 返信の「付箋にする」を押すと、その本文を引き継いだ付箋がボードに追加されます。議論をそのまま図へ移せます。</li>
      <li><strong>長い返信</strong> … 一定の長さを超えると途中で畳まれます。「続きを読む」で全文が出ます。</li>
      <li><strong>折りたたみ</strong> … 「付箋ボード」「返信」の見出しをクリックすると開閉できます。次に開いたときも同じ状態です。</li>
    </ul>
  </section>

  <section class="help-card">
    <h2>📌 付箋ボード</h2>
    <p>スレッド1件につき1枚のボードが付きます。閲覧は誰でも、編集はメール確認が済んだ方ができます。</p>

    <h3>基本の操作</h3>
    <ul>
      <li><strong>追加</strong> … 「+ 付箋を追加」を押すと、その場に入力欄が開きます。<strong>改行を含む複数行</strong>を書けます。Ctrl+Enter でも保存できます。</li>
      <li><strong>動かす</strong> … 付箋をドラッグします。位置は自動で保存されます。</li>
      <li><strong>やめる</strong> … 入力中に Esc を押すと取り消せます。</li>
    </ul>

    <h3>付箋のボタン</h3>
    <ul>
      <li><span class="help-key">的</span> 実装対象にする。青枠が付き、<strong>指示書</strong>に載るようになります。</li>
      <li><span class="help-key">連</span> ここから線をつなぐ。押してから相手の付箋をクリックすると、矢印付きの線が引かれます。線をクリックすると消せます。</li>
      <li><span class="help-key">編</span> 文面を変える。</li>
      <li><span class="help-key">色</span> 色で分類する。</li>
      <li><span class="help-key">×</span> 削除する。</li>
      <li><span class="help-key">履</span> この付箋の履歴を見る。数字は記録の件数です。</li>
    </ul>

    <h3>色による分類</h3>
    <p>色にはあらかじめ意味を持たせてあります。ボードの上に凡例が出ます。</p>
    <ul>
      <?php foreach ($colors as $key => $label): ?>
        <li><span class="help-swatch sticky-<?= e($key) ?>"></span><?= e($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="help-note">
      「実装対象」は色ではなく <span class="help-key">的</span> の印です。色と組み合わせて使えます。
    </p>

    <h3>他の人の付箋を触るとき</h3>
    <p>
      付箋は<strong>誰でも編集・削除できます</strong>。共同で整理するための仕組みですが、
      勝手に変えられたと感じないよう、
      <strong>自分以外が作った付箋を変更・削除するときは理由の入力が必要</strong>です。
    </p>
    <p>
      入力した理由は <span class="help-key">履</span> から誰でも確認できます。
      「いつ・誰が・何を・なぜ」変えたか、変更前の内容まで残ります。
      自分の付箋を触るときに理由は要りません。位置を動かすだけの場合も要りません。
    </p>
    <p class="help-note">
      削除しても実データは消えず、管理者が元に戻せます。
      1枚のボードに置ける付箋は<?= 200 ?>枚までです。
    </p>
  </section>

  <section class="help-card">
    <h2>📤 手元へ持ち出す (openManidoc連携)</h2>
    <p>スレッドの上部に2つのボタンがあります。目的が違います。</p>

    <h3>MD出力 — 議論の記録として</h3>
    <ul>
      <li>タイトル・起案者・タグ・本文と、すべての返信を Markdown にします。</li>
      <li>付箋のつながりを <code>mermaid</code> の図として添えます。</li>
      <li>話し合った内容をそのまま保管・共有したいときに使います。</li>
    </ul>

    <h3>指示書 — 次の作業へ渡すために</h3>
    <ul>
      <li><span class="help-key">的</span> 印を付けた付箋<strong>だけ</strong>を抜き出します。</li>
      <li>「背景 / 対象 / 関係 / 受け入れの目安 / 出典」の形にまとめます。</li>
      <li>各項目には、<strong>理由が入力された変更の履歴</strong>も添えます。なぜそうなったかが分からないと、受け取った側が判断を再現できないためです。</li>
    </ul>
    <p class="help-note">
      印を1つも付けていないと、対象が空の指示書になります。先にボードで <span class="help-key">的</span> を押してください。
    </p>

    <h3>実装結果を記録する</h3>
    <p>
      スレッドを立てた方と管理者は、「実装結果を記録する」からコミットやPRのURLを残せます。
      発想が何になったのかを辿れるようにするためのもので、指示書にも出典として載ります。
    </p>
  </section>

  <section class="help-card">
    <h2>🧵 自分のスレッドを管理する</h2>
    <p>自分が立てたスレッドには、次の操作ができます。</p>
    <ul>
      <li>
        <strong>一覧から下げる</strong> …
        公開一覧から消えます。ただし<strong>起案者と、返信したことがある人は今までどおり読み書きできます</strong>。
        まだ人に見せたくないが、関わった人とは続けたいときに使います。
      </li>
      <li>
        <strong>受付を終了</strong> …
        新しい返信を受け付けなくします。閲覧はできます。話がまとまったときに使います。
      </li>
    </ul>
    <p class="help-note">
      下げたスレッドは<a href="<?= bp() ?>/settings">表示の設定</a>の「自分が立ち上げたアイディア」に残るので、見失いません。
    </p>
  </section>

  <section class="help-card">
    <h2>🙂 表示の設定</h2>
    <p>ヘッダーのお名前をクリックすると設定画面が開きます。</p>
    <ul>
      <li><strong>表示名</strong> … いつでも変えられます。</li>
      <li>
        <strong>アバター</strong> …
        絵文字<?= count(Avatar::EMOJI) ?>種と背景色<?= count(Avatar::COLORS) ?>種から選びます。
        画像のアップロードはありません。選ばない場合は表示名の頭文字が出ます。
      </li>
      <li><strong>自分が立ち上げたアイディア</strong> … 一覧から下げたものも含めて並びます。</li>
    </ul>
  </section>

  <section class="help-card">
    <h2>✍️ Markdown の書き方</h2>
    <p>本文と返信で使えます。付箋は書いたままの文字で表示されます。</p>
    <div class="help-scroll">
      <table class="help-table">
        <thead><tr><th>書き方</th><th>結果</th></tr></thead>
        <tbody>
          <tr><td><code>## 見出し</code></td><td>見出しになります</td></tr>
          <tr><td><code>**太字**</code></td><td><strong>太字</strong></td></tr>
          <tr><td><code>- 項目</code></td><td>箇条書き</td></tr>
          <tr><td><code>1. 項目</code></td><td>番号付きの箇条書き</td></tr>
          <tr><td><code>&gt; 引用</code></td><td>引用として表示されます</td></tr>
          <tr><td><code>`コード`</code></td><td><code>コード</code></td></tr>
          <tr><td><code>```<br>まとまったコード<br>```</code></td><td>コードのかたまり</td></tr>
          <tr><td><code>[表示する文字](https://example.com)</code></td><td>リンク</td></tr>
        </tbody>
      </table>
    </div>
    <p class="help-note">
      安全のため、HTMLタグをそのまま書いても文字として表示されます。
    </p>
  </section>

  <section class="help-card">
    <h2>🛡️ 気持ちよく使うために</h2>
    <ul>
      <li>
        <strong>通報</strong> …
        不適切な投稿を見つけたら「通報」から知らせてください。理由を添えて管理者に届きます。
      </li>
      <li>
        <strong>管理者の対応</strong> …
        管理者は投稿を非表示にしたり、利用者を停止したりできます。
        管理者が非表示にしたスレッドは、起案者を含め誰も読めなくなります。
      </li>
      <li>
        <strong>自動の歯止め</strong> …
        短時間に大量の投稿ができないよう制限をかけています。
        普通にお使いになる分には気にする必要はありません。
      </li>
    </ul>
  </section>
</div>
