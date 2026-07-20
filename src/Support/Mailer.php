<?php
declare(strict_types=1);

namespace App\Support;

final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $driver = (string)App::config('mail.driver', 'file');

        if ($driver === 'mail') {
            // ロリポップ本番: mb_send_mail (日本語件名を自動エンコード)
            mb_language('Japanese');
            $from = (string)App::config('mail.from');
            $fromName = (string)App::config('mail.from_name', '');
            $encodedName = mb_encode_mimeheader($fromName, 'ISO-2022-JP');
            $headers = "From: {$encodedName} <{$from}>";
            $ok = mb_send_mail($to, $subject, $body, $headers);
            if (!$ok) {
                // 握りつぶすと「届かない理由が誰にも分からない」状態になる
                Log::error('メール送信に失敗しました', ['to' => $to, 'subject' => $subject]);
            }
            return $ok;
        }

        // 開発用: ファイルに書き出す
        $dir = dirname(__DIR__, 2) . '/storage/mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/' . date('Ymd_His') . '_' . substr(md5($to . $subject . microtime()), 0, 8) . '.txt';
        $content = "To: {$to}\nSubject: {$subject}\nDate: " . date('c') . "\n\n{$body}\n";
        return file_put_contents($file, $content) !== false;
    }
}
