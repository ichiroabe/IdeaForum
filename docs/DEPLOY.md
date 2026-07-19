# ロリポップへのデプロイ手順

対象アカウント: **ライトプラン** / ドメイン **fusion.upper.jp** / サーバー **lit-2**

ライトプランはSSHが使えないため、ローカルで `composer install` を済ませ、
`vendor/` ごとFTPSで転送する。

## なぜブラウザのロリポップ!FTPを使わないか

ロリポップ!FTP(ブラウザ版)には次の制限があり、本アプリの規模では使えない。

- 一度に選択できるのは20ファイル、一度にアップロードできるのは50ファイル
- フォルダの再帰アップロードができない(フォルダは1つずつ手作業で作成)
- zipのサーバー側展開ができない
- 画面上にも「アップロードするファイルが大量にある場合はFTPソフトをご利用ください」と案内がある

本アプリは vendor を含めて **約260ファイル / 65フォルダ**。
そのため `deploy\lolipop-deploy.ps1` でFTPS転送する。

## 設置場所について

`fusion.upper.jp` のWeb公開フォルダ直下には既存のmanidocサイトが稼働している。
IdeaForumはそれを避けて **`/ideaforum/` サブフォルダ** に設置し、
`https://fusion.upper.jp/ideaforum/` で公開する。

サブフォルダ設置に対応するため、設定の `base_path` にプレフィックスを指定する
(アプリ内のリンク・リダイレクトはすべてこの値を先頭に付けて生成される)。

## 1. データベース(作成済み)

ユーザー専用ページ → データベース で確認できる値:

| 項目 | 値 |
|---|---|
| データベース名 | `LAA1700269-irxlcy` |
| ホスト | `mysql80-2.lolipop.lan` |
| バージョン | MySQL 8.0 |
| ユーザー名 | `LAA1700269` |
| パスワード | ご自身で控えている値 |

phpMyAdmin にログインし、このDBに対して `sql/schema.sql` の内容をそのまま実行する。

## 2. 本番設定ファイルを作る

```powershell
copy config\config.production.sample.php config\config.production.php
```

`config.production.php` を開き、★印の2箇所(**DBパスワード**と**送信元メールアドレス**)を記入する。
このファイルは `.gitignore` 済みなのでGitHubには上がらない。
転送時にサーバー上の `config/config.php` として置かれる。

## 3. 転送する

FTPパスワードは環境変数から読ませる。スクリプトにもGitにも残らない。

```powershell
# パスワードを環境変数に入れる(この行はご自身で実行)
$env:IDEAFORUM_FTP_PASS = 'FTPのパスワード'

# 何が送られるかを確認(実際には送らない)
.\deploy\lolipop-deploy.ps1 -WhatIf

# 実行
.\deploy\lolipop-deploy.ps1
```

接続先は既定で `ftp-1.lolipop.jp` / アカウント `upper.jp-fusion` / 設置先 `/ideaforum`。
変更する場合は `-FtpHost` `-FtpUser` `-RemoteDir` で指定できる。

転送されないもの(意図的に除外):

- `config/config.php` … ローカル開発用。本番設定を壊すため
- `sql/` `docs/` `deploy/` `.git/` `composer.*` `README.md`
- `storage/mail/*.txt` … 開発中の確認メール(トークンを含むため)

## 4. 公開フォルダの保護

`src/` `config/` `templates/` `storage/` `sql/` にはアクセス拒否の `.htaccess` を同梱済み。
`vendor/` にだけ手動で同じ内容のファイルを置くこと(ロリポップ!FTPの「新規ファイル作成」で可):

```
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

## 5. 動作確認と管理者昇格

1. `https://fusion.upper.jp/ideaforum/` を開き、トップページが表示されることを確認
2. ご自身のメールアドレスで新規登録し、確認メールが届くことを確認
3. メール内のリンクを開いて登録を完了する
4. **登録が終わってから** phpMyAdmin で自分を管理者にする:

   ```sql
   UPDATE users SET role = 'admin' WHERE email = 'ここに登録したメールアドレス';
   ```

   この SQL は「登録済みのユーザー行」を書き換えるものなので、
   手順3を終える前に実行しても対象が0件で何も起きない。
   また `email` にはドメイン名ではなく、登録に使った**メールアドレス全体**を
   シングルクォートで囲んで指定する。

5. 再ログインするとヘッダーに「管理」リンクが出る

## 6. 運用メモ

- `rate_events` テーブルはアクセス時に確率的に自動掃除されるので cron 不要
- 荒らし対応は管理画面から(投稿の非表示・ユーザー停止)
- Turnstileを有効にする場合は Cloudflare でキーを取得し `config.production.php` に記入して再転送
- 更新時も同じスクリプトを再実行すればよい(上書き転送)
