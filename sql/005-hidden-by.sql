-- 非表示を「誰が下げたか」で区別する (004まで適用済みのDBに流す)
--
-- 投稿者が自分のスレッドを下げた場合と、管理者がモデレーションで下げた場合とでは
-- 意味が違う。前者は参加者が会話を続けられてよいが、後者で参加者を残すと
-- 荒らし本人も参加者なので締め出せず、モデレーションが機能しなくなる。

ALTER TABLE ideas
    ADD COLUMN hidden_by ENUM('admin','author') NULL AFTER status;

-- 既に非表示のものは、これまで管理者しか操作できなかったので admin 扱いにする
UPDATE ideas SET hidden_by = 'admin' WHERE status = 'hidden' AND hidden_by IS NULL;
