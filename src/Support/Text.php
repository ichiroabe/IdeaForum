<?php
declare(strict_types=1);

namespace App\Support;

use Parsedown;

final class Text
{
    private static ?Parsedown $parsedown = null;

    // Markdown → 安全なHTML (ParsedownのsafeModeで生HTML・危険URLを無効化)
    public static function markdown(string $md): string
    {
        if (self::$parsedown === null) {
            self::$parsedown = new Parsedown();
            self::$parsedown->setSafeMode(true);
            self::$parsedown->setBreaksEnabled(true); // 改行をそのまま<br>に(掲示板的な書き心地)
        }
        return self::$parsedown->text($md);
    }

    // "タグ1, タグ2" → 正規化済みタグ名配列 (最大5個・各50文字)
    public static function parseTags(string $input): array
    {
        $parts = preg_split('/[,、\s]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tags = [];
        foreach ($parts as $p) {
            // trim()はバイト単位で削るためマルチバイト文字が壊れる。正規表現で除去する。
            $name = mb_substr((string)preg_replace('/^[#\x{3000}\s]+|[#\x{3000}\s]+$/u', '', $p), 0, 50);
            if ($name !== '' && !in_array($name, $tags, true)) {
                $tags[] = $name;
            }
            if (count($tags) >= 5) {
                break;
            }
        }
        return $tags;
    }
}
