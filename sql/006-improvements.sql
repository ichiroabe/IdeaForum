-- 改善一括 (005まで適用済みのDBに流す)
-- 返信の編集/削除、未読の把握、指示書出力 に必要な変更をまとめている。

-- === 返信の編集・削除 ===
-- これまで返信は書いたら本人でも直せなかった。付箋には編集も履歴もあるのに
-- 落差が大きいため、返信にも同じ考え方を入れる。削除は論理削除。
ALTER TABLE posts
    ADD COLUMN edited_at  DATETIME        NULL AFTER created_at,
    ADD COLUMN deleted_at DATETIME        NULL AFTER edited_at,
    ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER deleted_at;

-- 返信の編集前の内容を残す。誰がいつ何を書き換えたかを追えるようにする。
CREATE TABLE IF NOT EXISTS post_edits (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id     BIGINT UNSIGNED NOT NULL,
    editor_id   BIGINT UNSIGNED NOT NULL,
    action      ENUM('edit','delete','restore') NOT NULL,
    before_body MEDIUMTEXT      NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_post_edits_post (post_id, created_at),
    CONSTRAINT fk_post_edits_editor FOREIGN KEY (editor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- === 未読の把握 ===
-- 自分が関わったスレッドに動きがあったことに気づけるようにする。
-- スレッドを開いた時刻を覚えておき、それ以降の更新を「新着」とみなす。
CREATE TABLE IF NOT EXISTS idea_reads (
    user_id BIGINT UNSIGNED NOT NULL,
    idea_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME        NOT NULL,
    PRIMARY KEY (user_id, idea_id),
    KEY ix_idea_reads_idea (idea_id),
    CONSTRAINT fk_idea_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_idea_reads_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- === 指示書としての持ち出し ===
-- 付箋のうち「実装する」と決めたものに印を付け、その印だけを
-- 指示書の型でMarkdown出力できるようにする。
ALTER TABLE notes
    ADD COLUMN is_target TINYINT(1) NOT NULL DEFAULT 0 AFTER color;

-- 発想が何になったかを記録する。指示書→実装の追跡が切れないようにするため。
ALTER TABLE ideas
    ADD COLUMN impl_url  VARCHAR(500) NULL AFTER hidden_by,
    ADD COLUMN impl_note VARCHAR(300) NULL AFTER impl_url;
