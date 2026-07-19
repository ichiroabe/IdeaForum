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

    public static function baseUrl(string $path = ''): string
    {
        return rtrim((string)self::config('base_url'), '/') . $path;
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
