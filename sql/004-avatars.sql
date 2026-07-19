-- アバター設定 (schema.sql 実行済みのDBに流す。002/003とは独立)
--
-- 画像はアップロードさせず、絵文字と背景色の組み合わせで表す。
-- 書き込み可能ディレクトリもGD拡張も不要で、不適切画像の監視もいらない。
-- 未設定のユーザーは id から色を決め、表示名の頭文字を出す。

ALTER TABLE users
    ADD COLUMN avatar_emoji VARCHAR(16) NULL AFTER display_name,
    ADD COLUMN avatar_color VARCHAR(20) NULL AFTER avatar_emoji;
