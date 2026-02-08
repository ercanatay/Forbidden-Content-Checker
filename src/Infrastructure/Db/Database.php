<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Db;

use PDO;
use PDOException;

final class Database
{
    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        $this->connect();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function connect(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        try {
            $this->pdo = new PDO('sqlite:' . $this->path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (PDOException $e) {
            throw new PDOException('Unable to connect to SQLite database: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
