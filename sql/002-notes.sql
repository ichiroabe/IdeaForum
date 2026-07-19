-- 付箋ボード機能の追加 (schema.sql を実行済みのDBに対して流す)
-- スレッド1件につきボード1枚。ボードは ideas と1対1なので専用テーブルは作らず、
-- 付箋が idea_id を直接持つ形にしている。

CREATE TABLE IF NOT EXISTS notes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id     BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,   -- 作成者
    body        VARCHAR(500)    NOT NULL,
    -- ボード上の位置。左上を原点とするピクセル値。
    pos_x       INT             NOT NULL DEFAULT 0,
    pos_y       INT             NOT NULL DEFAULT 0,
    color       ENUM('yellow','blue','green','pink','gray') NOT NULL DEFAULT 'yellow',
    -- 返信から作られた付箋は元の投稿を指す(投稿が消えたらNULLに戻す)
    source_post_id BIGINT UNSIGNED NULL,
    created_at  DATETIME        NOT NULL,
    updated_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_notes_idea (idea_id),
    KEY ix_notes_user (user_id),
    CONSTRAINT fk_notes_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_notes_post FOREIGN KEY (source_post_id) REFERENCES posts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 付箋同士をつなぐ線。向きあり(from → to)。
CREATE TABLE IF NOT EXISTS note_links (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id      BIGINT UNSIGNED NOT NULL,   -- 取得を1クエリで済ませるため冗長に持つ
    from_note_id BIGINT UNSIGNED NOT NULL,
    to_note_id   BIGINT UNSIGNED NOT NULL,
    label        VARCHAR(50)     NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    -- 同じ向きの重複線を張れないようにする
    UNIQUE KEY uq_note_links (from_note_id, to_note_id),
    KEY ix_note_links_idea (idea_id),
    KEY ix_note_links_to (to_note_id),
    CONSTRAINT fk_note_links_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
    CONSTRAINT fk_note_links_from FOREIGN KEY (from_note_id) REFERENCES notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_note_links_to FOREIGN KEY (to_note_id) REFERENCES notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_note_links_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
