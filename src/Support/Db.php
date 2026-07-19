<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                App::config('db.host'),
                App::config('db.name')
            );
            self::$pdo = new PDO($dsn, (string)App::config('db.user'), (string)App::config('db.password'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
