-- 管理者に昇格させるSQL。
--
-- 【重要】このSQLは「既にある users の行」を書き換えるもの。
-- 実行する前に、必ずサイトで会員登録を済ませておくこと。
-- 登録フォームが users に INSERT して初めて、書き換える対象ができる。
--
-- 実行の順番:
--   1. schema.sql を実行してテーブルを作る
--   2. アプリを転送する
--   3. サイトの /register で会員登録する   ← ここで INSERT される
--   4. このSQLを実行する                   ← ここで UPDATE
--
-- 手順3より前に実行すると、エラーにはならず「0 rows affected」で終わる。
--
-- status も 'active' にしているのは、確認メールが届かなかった場合でも
-- ログインできるようにするため。メール確認が正常に済んでいる場合は
-- 既に 'active' なので、指定しても影響はない。

UPDATE users
SET role = 'admin',
    `status` = 'active'
WHERE email = 'ここに登録したメールアドレス';

-- 実行後、1件更新されたことを確認する:
-- SELECT id, email, display_name, role, `status` FROM users;
