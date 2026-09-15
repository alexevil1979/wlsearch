<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

final class ServerInfo
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $ipv4,
        public readonly string $status,
        public readonly array $raw = [],
    ) {
    }

    public function isReady(): bool
    {
        if ($this->ipv4 === null || $this->ipv4 === '') {
            return false;
        }
        $s = strtolower(trim($this->status));
        // Timeweb: on = готово; installing/turning_on = ещё нет
        if (in_array($s, ['installing', 'turning_on', 'turning_off', 'hard_rebooting', 'soft_rebooting', 'off', 'blocked', 'unknown', ''], true)) {
            return false;
        }
        return in_array($s, ['on', 'active', 'running', 'started', 'ok'], true)
            || (!str_contains($s, 'install') && !str_contains($s, 'off') && !str_contains($s, 'reboot'));
    }
}
