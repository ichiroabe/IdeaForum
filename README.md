# IdeaForum

openManidoc と連携するアイディア出し掲示板。ロリポップ(ライトプラン)+ PHP + MySQL で動作する。

## 機能

- **メール認証つき会員制** — 実在メールアドレスの確認(MXレコードチェック+確認メールリンク)を経て投稿可能になる
- **アイディア投稿** — Markdown対応、タグ(最大5個)で分類
- **スレッド形式** — 他の人のアイディアに返信して発展させられる
- **MD出力** — スレッド全体を openManidoc に取り込める Markdown としてダウンロード(見出し構造つき)
- **管理機能** — 通報の受付、投稿の非表示、ユーザー停止

## 荒らし対策(標準装備)

| 対策 | 内容 |
|---|---|
| Cloudflare Turnstile | 登録フォームのbot対策(キー設定で有効化、未設定なら無効) |
| ハニーポット | 隠しフィールドに入力するbotを弾く |
| レート制限 | 登録 5回/日/IP、ログイン失敗 5回/10分、投稿間隔・日次上限 |
| 実在メール確認 | MX/Aレコード検証+使い捨てメールドメイン拒否リスト |
| メール確認必須 | 未確認アカウントは投稿不可 |
| CSRF保護 | 全POSTでトークン必須 |
| XSS対策 | Parsedown safeMode + 全出力エスケープ |
| SQLi対策 | PDOプリペアドステートメントのみ使用 |
| 通報・モデレーション | ユーザー通報 → 管理者が非表示/停止 |

## 技術構成

- PHP 8.1+ / Slim 4 (slim/psr7) / Parsedown
- MySQL 8.0 / MariaDB 10.6+ (utf8mb4)
- テンプレートは素のPHP(依存最小化のためTwig等は不使用)

```
public/      ← 公開フォルダ(ドキュメントルートに設定する)
src/         ← アプリ本体 (Controller / Support)
templates/   ← 画面テンプレート
config/      ← 設定 (config.sample.php をコピーして config.php を作る)
sql/         ← スキーマ
storage/     ← 開発用メール書き出し・ログ
```

## ローカル開発

```powershell
# 依存インストール (PHP 8.1+ と composer.phar)
php composer.phar install

# DB作成
mysql -u root -e "CREATE DATABASE ideaforum CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root ideaforum < sql/schema.sql

# 設定 (mail.driver を 'file' にすると storage/mail/ にメールが書き出される)
copy config\config.sample.php config\config.php  # 編集する

# 起動
php -S localhost:8080 -t public public/router.php
```

最初の管理者はSQLで昇格させる:

```sql
UPDATE users SET role='admin' WHERE email='あなたのメール';
```

## デプロイ

ロリポップへの手順は [docs/DEPLOY.md](docs/DEPLOY.md) を参照。

## openManidoc 連携

各スレッドの「MD出力」ボタンで以下の構造のMarkdownが得られる。
openManidoc の Markdown インポートで、H1がルート、H2が子ノードのツリーになる。

```markdown
# アイディアのタイトル
- 起案: 表示名 (日時)
- タグ: #タグ1 #タグ2
- 出典: URL

本文…

## 返信者名 (日時)
返信本文…
```
