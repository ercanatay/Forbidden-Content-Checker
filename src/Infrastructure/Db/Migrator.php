<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Db;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $schemaPath)
    {
    }

    public function migrate(): void
    {
        $sql = file_get_contents($this->schemaPath);
        if ($sql === false) {
            throw new \RuntimeException('Unable to read schema file: ' . $this->schemaPath);
        }

        $this->pdo->exec($sql);

        $this->pdo->exec("INSERT OR IGNORE INTO migration_versions (version, applied_at) VALUES ('v3-initial', datetime('now'))");

        $roles = ['admin', 'analyst', 'viewer'];
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO roles (name) VALUES (:name)');
        foreach ($roles as $role) {
            $stmt->execute([':name' => $role]);
        }

        $this->seedDefaultAdmin();
    }

    private function seedDefaultAdmin(): void
    {
        $email = getenv('FCC_ADMIN_EMAIL') ?: 'admin@example.com';
        $password = getenv('FCC_ADMIN_PASSWORD') ?: 'admin123!ChangeNow';

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if ($user) {
            return;
        }

        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $passwordHash = password_hash($password, $algo);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new \RuntimeException('Unable to generate password hash for default admin user.');
        }
        $insertUser = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, locale, mfa_enabled, mfa_secret, created_at, updated_at)
             VALUES (:email, :password_hash, :display_name, :locale, 0, NULL, datetime(\'now\'), datetime(\'now\'))'
        );
        $insertUser->execute([
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':display_name' => 'Administrator',
            ':locale' => 'en-US',
        ]);

        $userId = (int) $this->pdo->lastInsertId();
        $roleIdStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
        $roleId = (int) $roleIdStmt->fetchColumn();

        $assignRole = $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
        $assignRole->execute([':user_id' => $userId, ':role_id' => $roleId]);
    }
}
