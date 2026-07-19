<?php
declare(strict_types=1);

// テンプレートから直接呼ぶグローバルヘルパー

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// サイト内リンクの先頭に付けるベースパス。直下設置なら空文字を返す。
// テンプレートでは href 属性の先頭に埋め込んで使う。
function bp(): string
{
    return App\Support\App::basePath();
}

// 表示用日時 (例: 2026-07-19 14:05)
function fmt_date(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }
    return date('Y-m-d H:i', strtotime($datetime));
}
