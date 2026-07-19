<?php
declare(strict_types=1);

namespace App\Support;

final class Auth
{
    private static ?array $cachedUser = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['user_id'] ?? null;
            if ($id !== null) {
                $user = Db::query('SELECT * FROM users WHERE id = ?', [$id])->fetch();
                // BAN・削除済みユーザーはセッションごと無効化する
                if ($user && $user['status'] !== 'banned') {
                    self::$cachedUser = $user;
                } else {
                    unset($_SESSION['user_id']);
                }
            }
        }
        return self::$cachedUser;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int)$user['id'] : null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        self::$cachedUser = $user;
        self::$loaded = true;
        Db::query('UPDATE users SET last_login_at = ? WHERE id = ?', [Db::now(), $user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$cachedUser = null;
    }
}
