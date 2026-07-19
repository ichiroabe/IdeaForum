<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 利用者のアバター。画像は扱わず、絵文字と背景色だけで表す。
 * 未設定なら id から色を決め、表示名の頭文字を出すので、全員が必ず何か持つ。
 */
final class Avatar
{
    /** 選べる絵文字。自由入力だと不適切な組み合わせを作れてしまうため一覧から選ばせる。 */
    public const EMOJI = [
        '😀', '😎', '🤓', '🧐', '🙂', '😺', '🐶', '🐼', '🦊', '🐸',
        '🦉', '🐝', '🐬', '🌱', '🌸', '🍀', '🔥', '⭐', '🌙', '☀',
        '💡', '🔧', '📚', '✏', '🎨', '🎵', '⚙', '🚀', '🏔', '🍵',
    ];

    /** 選べる背景色。白文字が読めるよう十分に濃い色だけを並べている。 */
    public const COLORS = [
        'slate'  => '#64748b',
        'red'    => '#dc2626',
        'orange' => '#ea580c',
        'amber'  => '#b45309',
        'green'  => '#16a34a',
        'teal'   => '#0d9488',
        'blue'   => '#2563eb',
        'indigo' => '#4f46e5',
        'purple' => '#9333ea',
        'pink'   => '#db2777',
    ];

    public static function isValidEmoji(?string $e): bool
    {
        return $e !== null && $e !== '' && in_array($e, self::EMOJI, true);
    }

    public static function isValidColor(?string $c): bool
    {
        return $c !== null && array_key_exists($c, self::COLORS);
    }

    /** 未設定の人にも安定した色を割り当てる (同じ利用者は常に同じ色) */
    public static function colorFor(array $user): string
    {
        if (self::isValidColor($user['avatar_color'] ?? null)) {
            return self::COLORS[$user['avatar_color']];
        }
        $keys = array_keys(self::COLORS);
        return self::COLORS[$keys[((int)($user['id'] ?? 0)) % count($keys)]];
    }

    /** 絵文字が未設定なら表示名の頭文字を使う */
    public static function labelFor(array $user): string
    {
        if (self::isValidEmoji($user['avatar_emoji'] ?? null)) {
            return (string)$user['avatar_emoji'];
        }
        $name = trim((string)($user['display_name'] ?? ''));
        return $name === '' ? '?' : mb_substr($name, 0, 1);
    }

    /**
     * 表示用HTML。
     * $user は display_name / avatar_emoji / avatar_color / id を持つ配列。
     * $size は 'sm' | 'md' | 'lg'。
     */
    public static function html(array $user, string $size = 'md', bool $withName = false): string
    {
        $color = self::colorFor($user);
        $label = self::labelFor($user);
        $name = (string)($user['display_name'] ?? '');

        $html = sprintf(
            '<span class="avatar avatar-%s" style="background:%s" title="%s" aria-hidden="true">%s</span>',
            htmlspecialchars($size, ENT_QUOTES),
            htmlspecialchars($color, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($label, ENT_QUOTES)
        );

        if ($withName) {
            $html = '<span class="avatar-with-name">' . $html
                . '<span class="avatar-name">' . htmlspecialchars($name, ENT_QUOTES) . '</span></span>';
        }
        return $html;
    }
}
