<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 最小限のログ。storage/logs/app-YYYY-MM.log に追記する。
 *
 * 主目的はメール送信の失敗を残すこと。これまで Mailer::send() の戻り値を
 * 捨てていたため、確認メールが届かなくても誰も理由を知れなかった。
 */
final class Log
{
    public static function warn(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        // ログが書けないこと自体でアプリを止めない
        @file_put_contents($dir . '/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
