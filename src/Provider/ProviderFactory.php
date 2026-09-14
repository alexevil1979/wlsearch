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
            'selectel' => new SelectelProvider(),
            default => throw new \InvalidArgumentException('Unknown provider: ' . $provider),
        };
    }

    public static function isConfigured(string $provider): bool
    {
        return match (strtolower($provider)) {
            'timeweb' => (Env::get('TIMEWEB_API_TOKEN') ?? '') !== '',
            'selectel' => (Env::get('SELECTEL_USERNAME') ?? '') !== ''
                && (Env::get('SELECTEL_PASSWORD') ?? '') !== ''
                && (Env::get('SELECTEL_AUTH_URL') ?? '') !== ''
                && (Env::get('SELECTEL_FLAVOR_ID') ?? '') !== ''
                && (Env::get('SELECTEL_IMAGE_ID') ?? '') !== ''
                && (Env::get('SELECTEL_NETWORK_ID') ?? '') !== '',
            default => false,
        };
    }
}
