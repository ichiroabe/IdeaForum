<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 自分が関わったスレッドの新着を数える。
 *
 * スレッドを開いた時刻を idea_reads に覚えておき、それ以降に
 * updated_at が動いたものを「新着」とみなす。
 * 自分が起案したか、返信したことがあるスレッドだけが対象。
 */
final class Unread
{
    /** スレッドを開いたことを記録する */
    public static function markRead(int $ideaId): void
    {
        $me = Auth::id();
        if ($me === null) {
            return;
        }
        Db::query(
            'INSERT INTO idea_reads (user_id, idea_id, read_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)',
            [$me, $ideaId, Db::now()]
        );
    }

    /** 新着があるスレッドの件数 */
    public static function count(): int
    {
        $me = Auth::id();
        if ($me === null) {
            return 0;
        }
        return (int)Db::query(
            "SELECT COUNT(*) FROM ideas i
              WHERE i.status <> 'hidden'
                AND (i.user_id = ? OR EXISTS (SELECT 1 FROM posts p WHERE p.idea_id = i.id AND p.user_id = ?))
                AND i.updated_at > COALESCE(
                      (SELECT r.read_at FROM idea_reads r WHERE r.user_id = ? AND r.idea_id = i.id),
                      '1970-01-01')",
            [$me, $me, $me]
        )->fetchColumn();
    }

    /** そのスレッドに新着があるか (一覧の印用) */
    public static function idsWithUpdates(array $ideaIds): array
    {
        $me = Auth::id();
        if ($me === null || !$ideaIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ideaIds), '?'));
        $rows = Db::query(
            "SELECT i.id FROM ideas i
              WHERE i.id IN ({$in})
                AND (i.user_id = ? OR EXISTS (SELECT 1 FROM posts p WHERE p.idea_id = i.id AND p.user_id = ?))
                AND i.updated_at > COALESCE(
                      (SELECT r.read_at FROM idea_reads r WHERE r.user_id = ? AND r.idea_id = i.id),
                      '1970-01-01')",
            array_merge($ideaIds, [$me, $me, $me])
        )->fetchAll();
        return array_map('intval', array_column($rows, 'id'));
    }

    /** 未対応の通報件数 (管理者向け) */
    public static function openReports(): int
    {
        if (!Auth::isAdmin()) {
            return 0;
        }
        return (int)Db::query('SELECT COUNT(*) FROM reports WHERE resolved_at IS NULL')->fetchColumn();
    }
}
