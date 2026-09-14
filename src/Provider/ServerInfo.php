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
        $s = strtolower($this->status);
        return $this->ipv4 !== null
            && $this->ipv4 !== ''
            && in_array($s, ['on', 'active', 'running', 'started'], true);
    }
}
