-- 付箋の履歴管理と論理削除 (002-notes.sql の後に流す)
--
-- 目的: 発行者以外が編集・削除したとき、理由とともに記録を残して納得感を出す。
-- 物理削除すると「何が消されたか」を追えなくなるため、削除は論理削除に変える。

ALTER TABLE notes
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
    ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER deleted_at,
    ADD KEY ix_notes_alive (idea_id, deleted_at);

-- 付箋1枚ごとの操作履歴。
-- note_id にはあえて外部キーを張らない。将来 notes を物理削除しても
-- 履歴だけは残せるようにするため。
CREATE TABLE IF NOT EXISTS note_events (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id      BIGINT UNSIGNED NOT NULL,
    note_id      BIGINT UNSIGNED NOT NULL,
    actor_id     BIGINT UNSIGNED NOT NULL,
    action       ENUM('create','edit','color','delete','restore') NOT NULL,
    -- 発行者以外が編集・削除する場合は必須。発行者自身の操作では NULL。
    reason       VARCHAR(200) NULL,
    before_body  VARCHAR(500) NULL,
    after_body   VARCHAR(500) NULL,
    before_color VARCHAR(20)  NULL,
    after_color  VARCHAR(20)  NULL,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_note_events_note (note_id, created_at),
    KEY ix_note_events_idea (idea_id, created_at),
    CONSTRAINT fk_note_events_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
