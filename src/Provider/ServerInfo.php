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

    /** Timeweb: no_paid = «Не оплачен» в ЛК */
    public function isUnpaidOrBlocked(): bool
    {
        $s = strtolower(trim($this->status));
        return in_array($s, [
            'no_paid',
            'nopaid',
            'not_paid',
            'unpaid',
            'blocked',
            'permanent_blocked',
            'permanently_blocked',
        ], true)
            || str_contains($s, 'no_paid')
            || str_contains($s, 'unpaid');
    }

    public function isReady(): bool
    {
        if ($this->ipv4 === null || $this->ipv4 === '') {
            return false;
        }
        if ($this->isUnpaidOrBlocked()) {
            return false;
        }
        $s = strtolower(trim($this->status));
        // Только явно рабочие статусы — иначе no_paid/configuring ошибочно считались ready
        return in_array($s, ['on', 'active', 'running', 'started', 'ok'], true);
    }
}
