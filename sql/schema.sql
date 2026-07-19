-- IdeaForum schema (MySQL 8.0 / MariaDB 10.6+)
-- 文字コードは utf8mb4 固定。ロリポップのphpMyAdminからそのまま実行可能。

CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email           VARCHAR(255)    NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    display_name    VARCHAR(50)     NOT NULL,
    role            ENUM('user','admin')            NOT NULL DEFAULT 'user',
    status          ENUM('pending','active','banned') NOT NULL DEFAULT 'pending',
    created_at      DATETIME        NOT NULL,
    last_login_at   DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メール確認・パスワード再設定用トークン。トークンは平文を保存せずSHA-256ハッシュで持つ。
CREATE TABLE IF NOT EXISTS email_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    purpose     ENUM('verify','reset') NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_tokens_hash (token_hash),
    KEY ix_email_tokens_user (user_id),
    CONSTRAINT fk_email_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- アイディア(スレッドの起点)
CREATE TABLE IF NOT EXISTS ideas (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(200)    NOT NULL,
    body        MEDIUMTEXT      NOT NULL,
    status      ENUM('open','closed','hidden') NOT NULL DEFAULT 'open',
    posts_count INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL,
    updated_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_ideas_updated (status, updated_at),
    KEY ix_ideas_user (user_id),
    CONSTRAINT fk_ideas_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- スレッド内の返信投稿
CREATE TABLE IF NOT EXISTS posts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id     BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    body        MEDIUMTEXT      NOT NULL,
    status      ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_posts_idea (idea_id, status, created_at),
    KEY ix_posts_user (user_id),
    CONSTRAINT fk_posts_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
    id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name    VARCHAR(50)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idea_tags (
    idea_id BIGINT UNSIGNED NOT NULL,
    tag_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (idea_id, tag_id),
    KEY ix_idea_tags_tag (tag_id),
    CONSTRAINT fk_idea_tags_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
    CONSTRAINT fk_idea_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 汎用レートリミット。bucket例: "register:ip:1.2.3.4" / "post:user:5"
CREATE TABLE IF NOT EXISTS rate_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket      VARCHAR(120)    NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_rate_events (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通報
CREATE TABLE IF NOT EXISTS reports (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporter_id BIGINT UNSIGNED NOT NULL,
    target_type ENUM('idea','post') NOT NULL,
    target_id   BIGINT UNSIGNED NOT NULL,
    reason      VARCHAR(500)    NOT NULL,
    created_at  DATETIME        NOT NULL,
    resolved_at DATETIME        NULL,
    PRIMARY KEY (id),
    KEY ix_reports_open (resolved_at, created_at),
    CONSTRAINT fk_reports_user FOREIGN KEY (reporter_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
