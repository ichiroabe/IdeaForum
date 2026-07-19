# ロリポップ(ライトプラン)デプロイ手順

ライトプランはSSHが使えないため、**ローカルで composer install を済ませて vendor/ ごとFTPアップロード**する。

## 1. ロリポップ側の準備

1. ユーザー専用ページ → サーバーの管理・設定 → **PHP設定** で PHP 8.x (CGI版) を選択
2. **データベース** → 作成。ホスト名 / DB名 / ユーザー名 / パスワードを控える
3. phpMyAdmin にログインし、作成したDBに対して `sql/schema.sql` の内容を実行

## 2. 設定ファイル

`config/config.sample.php` を `config/config.php` にコピーして編集:

- `base_url` — 公開URL (例: `https://forum.example.com`)
- `debug` — **必ず false**
- `db` — 手順1で控えた値
- `mail.driver` — `'mail'` / `mail.from` — ロリポップで使えるメールアドレス
- `turnstile` — Cloudflareダッシュボードで取得した site_key / secret_key
  (空のままなら Turnstile 無効。ハニーポットとレート制限は常時有効)

## 3. アップロード構成

FTP(ロリポップFTPまたはFileZilla等)で以下をアップロードする:

```
ideaforum/            ← FTPルート直下に作るフォルダ
├─ public/            ← ここを公開フォルダに設定
├─ src/
├─ templates/
├─ config/            ← config.php を含める
├─ vendor/            ← ローカルで composer install した結果
└─ storage/
```

- `sql/`、`composer.json` 等はアップロード不要(あっても害はない)
- **ユーザー専用ページ → 独自ドメイン/サブドメイン設定で、公開(アップロード)フォルダを `ideaforum/public` に設定する。**
  これで `src/` や `config/` にWebから直接アクセスできなくなる。
- 公開フォルダを分けられない構成の場合に備えて、`config/` `src/` `templates/` `sql/` `storage/` には
  アクセス拒否の `.htaccess` を同梱してある。`vendor/` にだけ手動で同じ内容の `.htaccess` を置くこと:
  ```
  Require all denied
  ```

## 4. 動作確認と初期設定

1. 公開URLを開きトップページが表示されることを確認
2. 自分のメールアドレスで新規登録し、確認メールが届くことを確認
3. phpMyAdmin で自分を管理者に昇格:
   ```sql
   UPDATE users SET role='admin' WHERE email='自分のメール';
   ```
4. 再ログインするとヘッダーに「管理」リンクが出る

## 5. 運用メモ

- `rate_events` テーブルはアクセス時に確率的に自動掃除されるので cron 不要
- 荒らし対応: 管理画面から投稿の非表示・ユーザー停止が可能
- Turnstileのウィジェットが表示されない場合は site_key のドメイン設定を確認
