<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $host = (string) Config::get('db_host', '127.0.0.1');
        $port = (int) Config::get('db_port', 3306);
        $name = (string) Config::get('db_name', '');
        $user = (string) Config::get('db_user', 'root');
        $pass = (string) Config::get('db_pass', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => true, // Critical: preserve exact DECIMAL(30,8) strings!
        ]);
        self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $tzOffset = date('P');
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $tzOffset)) {
            self::$pdo->exec("SET time_zone = '{$tzOffset}'");
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Executes an atomic InnoDB transaction with automatic rollback on any exception.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $nested = $pdo->inTransaction();
        if (!$nested) {
            $pdo->beginTransaction();
        }
        try {
            $res = $fn($pdo);
            if (!$nested) {
                $pdo->commit();
            }
            return $res;
        } catch (\Throwable $e) {
            if (!$nested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
