<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Auth;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use PDO;

final class AuthService
{
    private ?string $dummyVerifyHash = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly TotpService $totpService,
        private readonly string $sessionName,
        private readonly string $appSecret
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(Request $request): ?array
    {
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            return $_SESSION['user'];
        }

        $authHeader = $request->header('Authorization') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token . $this->appSecret);
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.display_name, u.locale, u.mfa_enabled
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > datetime(\'now\'))
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $hash]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        $roles = $this->rolesForUser((int) $user['id']);
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'locale' => (string) $user['locale'],
            'mfa_enabled' => (bool) $user['mfa_enabled'],
            'roles' => $roles,
            'auth_type' => 'token',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $email, string $password, ?string $otpCode, string $ip, string $userAgent): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => mb_strtolower(trim($email), 'UTF-8')]);
        $user = $stmt->fetch();

        $hashToVerify = $user
            ? (string) $user['password_hash']
            : $this->resolveDummyVerifyHash();
        $verified = password_verify($password, $hashToVerify);

        if (!$user || !$verified) {
            $this->audit(null, 'auth.login.failed', ['email' => $email, 'ip' => $ip]);
            throw new \RuntimeException('Invalid credentials.');
        }

        if ((int) $user['mfa_enabled'] === 1) {
            if (!$otpCode || !$this->totpService->verify((string) $user['mfa_secret'], $otpCode)) {
                $this->audit((int) $user['id'], 'auth.login.mfa_failed', ['ip' => $ip]);
                throw new \RuntimeException('Invalid MFA code.');
            }
        }

        $roles = $this->rolesForUser((int) $user['id']);
        $sessionPayload = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'locale' => (string) $user['locale'],
            'mfa_enabled' => (bool) $user['mfa_enabled'],
            'roles' => $roles,
            'auth_type' => 'session',
        ];

        session_regenerate_id(true);
        $_SESSION['user'] = $sessionPayload;
        $_SESSION['session_started_at'] = time();

        $this->persistSession((int) $user['id'], session_id(), $ip, $userAgent);
        $this->audit((int) $user['id'], 'auth.login.success', ['ip' => $ip]);

        return $sessionPayload;
    }

    public function logout(?int $userId = null): void
    {
        $sid = session_id();
        if ($sid !== '') {
            $stmt = $this->pdo->prepare('UPDATE sessions SET revoked_at = datetime(\'now\') WHERE session_id = :session_id');
            $stmt->execute([':session_id' => $sid]);
        }

        if ($userId !== null) {
            $this->audit($userId, 'auth.logout', []);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie($this->sessionName, '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public function issueApiToken(int $userId, string $name, string $scope, ?string $expiresAt): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken . $this->appSecret);

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, scope, expires_at, created_at)
             VALUES (:user_id, :name, :token_hash, :scope, :expires_at, datetime(\'now\'))'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':token_hash' => $hash,
            ':scope' => $scope,
            ':expires_at' => $expiresAt,
        ]);

        $this->audit($userId, 'auth.token.issued', ['name' => $name, 'scope' => $scope]);

        return $rawToken;
    }

    /**
     * @return array<int, string>
     */
    public function rolesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.name
             FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        return array_map(static fn (array $row): string => (string) $row['name'], $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $details
     */
    public function audit(?int $userId, string $event, array $details): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, event, details_json, created_at)
             VALUES (:user_id, :event, :details_json, datetime(\'now\'))'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':event' => $event,
            ':details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function persistSession(int $userId, string $sessionId, string $ip, string $userAgent): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (user_id, session_id, ip_address, user_agent, created_at, last_seen_at)
             VALUES (:user_id, :session_id, :ip_address, :user_agent, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionId,
            ':ip_address' => $ip,
            ':user_agent' => mb_substr($userAgent, 0, 512),
        ]);
    }

    private function resolveDummyVerifyHash(): string
    {
        if ($this->dummyVerifyHash !== null) {
            return $this->dummyVerifyHash;
        }

        try {
            $stmt = $this->pdo->query("SELECT password_hash FROM users WHERE password_hash IS NOT NULL AND password_hash <> '' LIMIT 1");
            if ($stmt !== false) {
                $candidate = $stmt->fetchColumn();
                if (is_string($candidate) && $candidate !== '' && $this->isSupportedPasswordHash($candidate)) {
                    $this->dummyVerifyHash = $candidate;
                    return $this->dummyVerifyHash;
                }
            }
        } catch (\Throwable) {
            // Fall back to generating a local dummy hash.
        }

        $seed = 'dummy:' . $this->appSecret;
        $generated = password_hash($seed, PASSWORD_ARGON2ID);
        if (!is_string($generated) || !$this->isSupportedPasswordHash($generated)) {
            $generated = password_hash($seed, PASSWORD_DEFAULT);
            if (!is_string($generated) || !$this->isSupportedPasswordHash($generated)) {
                throw new \RuntimeException('Unable to initialize dummy password hash.');
            }
        }

        $this->dummyVerifyHash = $generated;

        return $this->dummyVerifyHash;
    }

    private function isSupportedPasswordHash(string $hash): bool
    {
        $info = password_get_info($hash);

        return is_array($info) && ((int) ($info['algo'] ?? 0)) !== 0;
    }
}
