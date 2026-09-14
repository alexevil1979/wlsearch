<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use Wlsearch\Support\Env;

final class ProviderFactory
{
    public static function make(string $provider): ProviderInterface
    {
        return match (strtolower($provider)) {
            'timeweb' => new TimewebProvider(),
            'selectel' => throw new \RuntimeException('Selectel provider is Phase 3 — not implemented yet'),
            default => throw new \InvalidArgumentException('Unknown provider: ' . $provider),
        };
    }

    public static function isConfigured(string $provider): bool
    {
        return match (strtolower($provider)) {
            'timeweb' => (Env::get('TIMEWEB_API_TOKEN') ?? '') !== '',
            'selectel' => false,
            default => false,
        };
    }
}
