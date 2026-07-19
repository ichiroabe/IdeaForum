<?php
declare(strict_types=1);

namespace App\Support;

final class RateLimiter
{
    /**
     * bucket内の直近windowSeconds秒のイベント数がmax未満なら記録してtrue。
     * 上限に達していればfalse(記録しない)。
     */
    public static function hit(string $bucket, int $max, int $windowSeconds): bool
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        $count = (int)Db::query(
            'SELECT COUNT(*) AS c FROM rate_events WHERE bucket = ? AND created_at >= ?',
            [$bucket, $since]
        )->fetchColumn();

        if ($count >= $max) {
            return false;
        }
        Db::query('INSERT INTO rate_events (bucket, created_at) VALUES (?, ?)', [$bucket, Db::now()]);

        // ついで掃除: 古い行を確率的に削除(cron不要で肥大化を防ぐ)
        if (random_int(1, 50) === 1) {
            Db::query('DELETE FROM rate_events WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 172800)]);
        }
        return true;
    }

    // 記録せず確認だけ(失敗時のみ記録したいログイン試行用)
    public static function tooMany(string $bucket, int $max, int $windowSeconds): bool
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        $count = (int)Db::query(
            'SELECT COUNT(*) AS c FROM rate_events WHERE bucket = ? AND created_at >= ?',
            [$bucket, $since]
        )->fetchColumn();
        return $count >= $max;
    }

    public static function record(string $bucket): void
    {
        Db::query('INSERT INTO rate_events (bucket, created_at) VALUES (?, ?)', [$bucket, Db::now()]);
    }
}
