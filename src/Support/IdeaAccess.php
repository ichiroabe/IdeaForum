<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 非表示スレッドを誰が見られるかの判断。
 *
 * 判定を各コントローラに散らすと必ず抜け穴ができるので、ここに集約する。
 * 詳細ページ・付箋API・MD出力・一覧のすべてがこの規則に従う。
 *
 *   通常(open/closed) … 誰でも
 *   投稿者が非表示     … 投稿者本人 + そのスレッドに返信した人 + 管理者
 *   管理者が非表示     … 管理者のみ
 *
 * 管理者による非表示で参加者を残すと、荒らし本人も「参加者」なので
 * 締め出せなくなる。そのため後者は例外を作らない。
 */
final class IdeaAccess
{
    public static function canView(array $idea): bool
    {
        if ($idea['status'] !== 'hidden') {
            return true;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        if (($idea['hidden_by'] ?? 'admin') !== 'author') {
            return false;   // 管理者が下げたものは参加者にも見せない
        }
        return self::isParticipant((int)$idea['id'], (int)$idea['user_id']);
    }

    /** 返信できるか。閲覧できて、かつスレッドが閉じられていないこと。 */
    public static function canReply(array $idea): bool
    {
        $user = Auth::user();
        if ($user === null || $user['status'] !== 'active') {
            return false;
        }
        return $idea['status'] !== 'closed' && self::canView($idea);
    }

    /** 非表示の切替を行えるか (投稿者本人、または管理者) */
    public static function canToggleVisibility(array $idea): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        // 管理者が下げたものを投稿者が戻すことはできない
        if ($idea['status'] === 'hidden' && ($idea['hidden_by'] ?? 'admin') === 'admin') {
            return false;
        }
        return Auth::id() !== null && (int)$idea['user_id'] === Auth::id();
    }

    /** 起案者か、そのスレッドに返信したことがある人 */
    private static function isParticipant(int $ideaId, int $authorId): bool
    {
        $me = Auth::id();
        if ($me === null) {
            return false;
        }
        if ($me === $authorId) {
            return true;
        }
        return (bool)Db::query(
            'SELECT 1 FROM posts WHERE idea_id = ? AND user_id = ? LIMIT 1',
            [$ideaId, $me]
        )->fetchColumn();
    }

    /**
     * 一覧の絞り込み条件。上の規則をSQLで表したもの。
     * [条件文, パラメータ] を返す。条件不要なら [null, []]。
     */
    public static function listCondition(): array
    {
        if (Auth::isAdmin()) {
            return [null, []];   // 管理者は非表示も含めて全部見る
        }
        $me = Auth::id();
        if ($me === null) {
            return ["i.status <> 'hidden'", []];
        }
        // 自分が下げたもの・自分が参加しているものは、非表示でも一覧に残す
        return [
            "(i.status <> 'hidden' OR (i.hidden_by = 'author' AND (i.user_id = ?
              OR EXISTS (SELECT 1 FROM posts p WHERE p.idea_id = i.id AND p.user_id = ?))))",
            [$me, $me],
        ];
    }
}
