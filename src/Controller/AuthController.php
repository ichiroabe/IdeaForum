<?php
declare(strict_types=1);

namespace App\Controller;

use App\Support\App;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Mailer;
use App\Support\RateLimiter;
use App\Support\Turnstile;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function showRegister(Request $request, Response $response): Response
    {
        return View::render($response, 'register', ['title' => '新規登録']);
    }

    public function register(Request $request, Response $response): Response
    {
        $ip = App::clientIp();

        // ハニーポット: 隠しフィールドに入力があればbot。成功したふりをして終わる。
        if (trim((string)($_POST['website'] ?? '')) !== '') {
            return View::render($response, 'verify_notice', ['title' => '確認メールを送信しました']);
        }

        if (!Turnstile::verify($_POST['cf-turnstile-response'] ?? null, $ip)) {
            Flash::add('error', '認証チェックに失敗しました。もう一度お試しください。');
            return redirect($response, '/register');
        }

        if (!RateLimiter::hit('register:ip:' . $ip, (int)App::config('limits.register_per_ip_per_day', 5), 86400)) {
            Flash::add('error', '登録の試行回数が多すぎます。しばらく経ってからお試しください。');
            return redirect($response, '/register');
        }

        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $displayName = trim((string)($_POST['display_name'] ?? ''));

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません。';
        } else {
            $domain = substr($email, strrpos($email, '@') + 1);
            $blocked = (array)App::config('blocked_email_domains', []);
            if (in_array($domain, $blocked, true)) {
                $errors[] = 'このメールドメインは利用できません。';
            } elseif (!self::domainAcceptsMail($domain)) {
                $errors[] = 'メールドメインが見つかりません。実在するメールアドレスを入力してください。';
            }
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'パスワードは8文字以上にしてください。';
        }
        if ($displayName === '' || mb_strlen($displayName) > 50) {
            $errors[] = '表示名は1〜50文字で入力してください。';
        }

        if (!$errors) {
            $exists = Db::query('SELECT id FROM users WHERE email = ?', [$email])->fetch();
            if ($exists) {
                $errors[] = 'このメールアドレスは既に登録されています。';
            }
        }

        if ($errors) {
            foreach ($errors as $e) {
                Flash::add('error', $e);
            }
            return redirect($response, '/register');
        }

        Db::query(
            'INSERT INTO users (email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?)',
            [$email, password_hash($password, PASSWORD_DEFAULT), $displayName, Db::now()]
        );
        $userId = Db::lastId();
        $this->sendVerifyMail($userId, $email);

        return View::render($response, 'verify_notice', ['title' => '確認メールを送信しました']);
    }

    public function verify(Request $request, Response $response): Response
    {
        $token = (string)($request->getQueryParams()['token'] ?? '');
        $row = $token === '' ? false : Db::query(
            'SELECT * FROM email_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), 'verify', Db::now()]
        )->fetch();

        if (!$row) {
            Flash::add('error', '確認リンクが無効か期限切れです。ログイン画面から確認メールを再送できます。');
            return redirect($response, '/login');
        }

        Db::query('UPDATE email_tokens SET used_at = ? WHERE id = ?', [Db::now(), $row['id']]);
        Db::query("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'", [$row['user_id']]);

        $user = Db::query('SELECT * FROM users WHERE id = ?', [$row['user_id']])->fetch();
        if ($user && $user['status'] === 'active') {
            Auth::login($user);
            Flash::add('success', 'メール確認が完了しました。ようこそ!');
            return redirect($response, '/');
        }
        Flash::add('error', 'このアカウントは現在利用できません。');
        return redirect($response, '/login');
    }

    public function showLogin(Request $request, Response $response): Response
    {
        return View::render($response, 'login', ['title' => 'ログイン']);
    }

    public function login(Request $request, Response $response): Response
    {
        $ip = App::clientIp();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        $ipBucket = 'loginfail:ip:' . $ip;
        $emailBucket = 'loginfail:email:' . $email;
        if (RateLimiter::tooMany($ipBucket, (int)App::config('limits.login_fail_per_ip_10min', 10), 600)
            || RateLimiter::tooMany($emailBucket, (int)App::config('limits.login_fail_per_email_10min', 5), 600)) {
            Flash::add('error', 'ログイン試行が多すぎます。10分ほど待ってからお試しください。');
            return redirect($response, '/login');
        }

        $user = Db::query('SELECT * FROM users WHERE email = ?', [$email])->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            RateLimiter::record($ipBucket);
            RateLimiter::record($emailBucket);
            Flash::add('error', 'メールアドレスまたはパスワードが違います。');
            return redirect($response, '/login');
        }
        if ($user['status'] === 'banned') {
            Flash::add('error', 'このアカウントは停止されています。');
            return redirect($response, '/login');
        }
        if ($user['status'] === 'pending') {
            Flash::add('error', 'メール確認が完了していません。確認メールを再送しますか?');
            return redirect($response, '/resend?email=' . urlencode($email));
        }

        Auth::login($user);
        Flash::add('success', 'ログインしました。');
        return redirect($response, '/');
    }

    public function logout(Request $request, Response $response): Response
    {
        Auth::logout();
        Flash::add('success', 'ログアウトしました。');
        return redirect($response, '/');
    }

    public function showResend(Request $request, Response $response): Response
    {
        $email = (string)($request->getQueryParams()['email'] ?? '');
        return View::render($response, 'resend', ['title' => '確認メール再送', 'email' => $email]);
    }

    public function resend(Request $request, Response $response): Response
    {
        $ip = App::clientIp();
        if (!RateLimiter::hit('resend:ip:' . $ip, 5, 3600)) {
            Flash::add('error', '再送回数が多すぎます。しばらく待ってからお試しください。');
            return redirect($response, '/login');
        }
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $user = Db::query("SELECT * FROM users WHERE email = ? AND status = 'pending'", [$email])->fetch();
        if ($user) {
            $this->sendVerifyMail((int)$user['id'], $email);
        }
        // ユーザーが存在するかどうかは明かさない
        return View::render($response, 'verify_notice', ['title' => '確認メールを送信しました']);
    }

    private function sendVerifyMail(int $userId, string $email): void
    {
        $token = bin2hex(random_bytes(32));
        Db::query(
            'INSERT INTO email_tokens (user_id, token_hash, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, hash('sha256', $token), 'verify', date('Y-m-d H:i:s', time() + 86400), Db::now()]
        );
        $url = App::baseUrl('/verify?token=' . $token);
        $site = (string)App::config('site_name');
        $body = "{$site} へのご登録ありがとうございます。\n\n"
            . "以下のURLを開いてメールアドレスの確認を完了してください(24時間有効):\n\n"
            . "{$url}\n\n"
            . "心当たりがない場合はこのメールを破棄してください。\n";
        Mailer::send($email, "【{$site}】メールアドレスの確認", $body);
    }

    // MXまたはAレコードがあるドメインのみ受け付ける(実在メールの最低限チェック)
    private static function domainAcceptsMail(string $domain): bool
    {
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;
        return checkdnsrr($ascii, 'MX') || checkdnsrr($ascii, 'A');
    }
}
