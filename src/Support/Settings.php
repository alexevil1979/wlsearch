<?php

declare(strict_types=1);

namespace Wlsearch\Support;

use PDO;

final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        if (array_key_exists($key, $all) && $all[$key] !== '') {
            return $all[$key];
        }
        $env = Env::get($key);
        if ($env !== null && $env !== '') {
            return $env;
        }
        return $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        $pdo = Database::tryPdo();
        if ($pdo === null) {
            return self::$cache;
        }
        try {
            $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                self::$cache[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable) {
            // not migrated yet
        }
        return self::$cache;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
