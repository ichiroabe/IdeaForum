<?php
declare(strict_types=1);

// テンプレートから直接呼ぶグローバルヘルパー

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// 表示用日時 (例: 2026-07-19 14:05)
function fmt_date(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }
    return date('Y-m-d H:i', strtotime($datetime));
}
