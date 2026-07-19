<?php
declare(strict_types=1);

namespace App\Support;

// 設定の置き場。DIコンテナを持ち込むほどの規模ではないのでstaticで済ませる。
final class App
{
    private static array $config = [];

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    // サブフォルダ設置時のプレフィックス (例: '/ideaforum')。直下設置なら空文字。
    public static function basePath(): string
    {
        return rtrim((string)self::config('base_path', ''), '/');
    }

    // サイト内リンク用の絶対パス
    public static function path(string $path = ''): string
    {
        return self::basePath() . $path;
    }

    // メール本文などに入れる完全URL
    public static function baseUrl(string $path = ''): string
    {
        return rtrim((string)self::config('base_url'), '/') . self::path($path);
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
