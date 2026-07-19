<?php
declare(strict_types=1);

namespace App\Support;

final class Turnstile
{
    public static function enabled(): bool
    {
        return App::config('turnstile.site_key') !== ''
            && App::config('turnstile.secret_key') !== '';
    }

    public static function siteKey(): string
    {
        return (string)App::config('turnstile.site_key');
    }

    // フォームに埋め込むウィジェットHTML(無効時は空文字)
    public static function widget(): string
    {
        if (!self::enabled()) {
            return '';
        }
        return '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars(self::siteKey(), ENT_QUOTES) . '"></div>'
            . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }

    public static function verify(?string $token, string $ip): bool
    {
        if (!self::enabled()) {
            return true; // キー未設定なら素通し(ローカル開発)
        }
        if (!is_string($token) || $token === '') {
            return false;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret'   => App::config('turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return false;
        }
        $json = json_decode($raw, true);
        return is_array($json) && ($json['success'] ?? false) === true;
    }
}
