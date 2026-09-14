<?php

declare(strict_types=1);

namespace Wlsearch\Auth;

use Wlsearch\Support\Database;
use Wlsearch\Support\Env;

final class AuthService
{
    private const SESSION_USER = 'admin_user';
    private const RATE_KEY = 'login_attempts';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name = Env::get('SESSION_NAME', 'wlsearch_sess') ?? 'wlsearch_sess';
        session_name($name);

        $secure = Env::bool('SESSION_SECURE', true);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function check(): bool
    {
        return !empty($_SESSION[self::SESSION_USER]);
    }

    public static function user(): ?array
    {
        return $_SESSION[self::SESSION_USER] ?? null;
    }

    public static function attempt(string $login, string $password): bool
    {
        if (self::isLockedOut()) {
            return false;
        }

        $ok = false;

        // Prefer DB users if table has rows; fallback to env admin.
        $pdo = Database::tryPdo();
        if ($pdo !== null) {
            try {
                $stmt = $pdo->prepare('SELECT id, login, password_hash FROM admin_users WHERE login = ? AND is_active = 1 LIMIT 1');
                $stmt->execute([$login]);
                $row = $stmt->fetch();
                if ($row && password_verify($password, $row['password_hash'])) {
                    $_SESSION[self::SESSION_USER] = [
                        'id' => (int) $row['id'],
                        'login' => $row['login'],
                        'source' => 'db',
                    ];
                    $ok = true;
                }
            } catch (\Throwable) {
                // Table may not exist yet before migrate.
            }
        }

        if (!$ok) {
            $envLogin = Env::get('ADMIN_LOGIN', 'admin');
            $envPassword = Env::get('ADMIN_PASSWORD', '');
            if (
                $envLogin !== null
                && $envPassword !== null
                && $envPassword !== ''
                && hash_equals($envLogin, $login)
                && hash_equals($envPassword, $password)
            ) {
                $_SESSION[self::SESSION_USER] = [
                    'id' => 0,
                    'login' => $envLogin,
                    'source' => 'env',
                ];
                $ok = true;
            }
        }

        if ($ok) {
            self::clearAttempts();
            session_regenerate_id(true);
            return true;
        }

        self::registerFailedAttempt();
        return false;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_USER]);
        session_regenerate_id(true);
    }

    public static function isLockedOut(): bool
    {
        $data = $_SESSION[self::RATE_KEY] ?? null;
        if (!is_array($data)) {
            return false;
        }
        $lockUntil = (int) ($data['lock_until'] ?? 0);
        return $lockUntil > time();
    }

    public static function lockRemainingSeconds(): int
    {
        $data = $_SESSION[self::RATE_KEY] ?? null;
        if (!is_array($data)) {
            return 0;
        }
        return max(0, (int) ($data['lock_until'] ?? 0) - time());
    }

    private static function registerFailedAttempt(): void
    {
        $max = Env::int('LOGIN_MAX_ATTEMPTS', 5);
        $lockSeconds = Env::int('LOGIN_LOCKOUT_SECONDS', 900);
        $data = $_SESSION[self::RATE_KEY] ?? ['count' => 0, 'lock_until' => 0];
        $data['count'] = (int) $data['count'] + 1;
        if ($data['count'] >= $max) {
            $data['lock_until'] = time() + $lockSeconds;
            $data['count'] = 0;
        }
        $_SESSION[self::RATE_KEY] = $data;
    }

    private static function clearAttempts(): void
    {
        unset($_SESSION[self::RATE_KEY]);
    }
}
