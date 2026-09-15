<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use Wlsearch\Support\Env;
use Wlsearch\Support\Settings;

/**
 * Per-account config bag (credentials + options). Falls back to Settings/.env only in legacy mode.
 */
final class AccountBag
{
    /** @param array<string, string> $data */
    public function __construct(
        private array $data,
        public readonly ?int $accountId = null,
        public readonly string $accountName = '',
        private bool $legacyFallback = false,
    ) {
    }

    public static function legacy(string $provider): self
    {
        return new self([], null, 'legacy-' . $provider, true);
    }

    /** @param array<string, mixed> $row provider_accounts row */
    public static function fromAccountRow(array $row): self
    {
        $creds = json_decode((string) ($row['credentials_json'] ?? '{}'), true);
        $cfg = json_decode((string) ($row['config_json'] ?? '{}'), true);
        if (!is_array($creds)) {
            $creds = [];
        }
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $data = [];
        foreach (array_merge($cfg, $creds) as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v) || is_float($v) || is_bool($v))) {
                $data[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
            }
        }
        return new self(
            $data,
            isset($row['id']) ? (int) $row['id'] : null,
            (string) ($row['name'] ?? ''),
            false,
        );
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] !== '') {
            return $this->data[$key];
        }
        if ($this->legacyFallback) {
            return Settings::get($key, Env::get($key, $default));
        }
        return $default;
    }

    public function require(string $key): string
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            $who = $this->accountName !== '' ? $this->accountName : 'account';
            throw new \RuntimeException("Не задано {$key} для {$who}");
        }
        return $v;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return (int) $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public function logTag(): string
    {
        if ($this->accountId !== null) {
            return 'acc' . $this->accountId;
        }
        return 'legacy';
    }
}
