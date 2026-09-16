<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use Wlsearch\Support\Env;

final class ProviderFactory
{
    public static function make(string $provider, ?int $accountId = null): ProviderInterface
    {
        $provider = strtolower($provider);
        $bag = (new ProviderAccountService())->bag($accountId, $provider);
        return match ($provider) {
            'timeweb' => new TimewebProvider($bag),
            'selectel' => new SelectelProvider($bag),
            'yandex' => new YandexCloudProvider($bag),
            default => throw new \InvalidArgumentException('Unknown provider: ' . $provider),
        };
    }

    /** @param array<string, mixed> $run */
    public static function forRun(array $run): ProviderInterface
    {
        $accountId = isset($run['provider_account_id']) && $run['provider_account_id'] !== null && $run['provider_account_id'] !== ''
            ? (int) $run['provider_account_id']
            : null;
        if ($accountId !== null && $accountId <= 0) {
            $accountId = null;
        }
        return self::make((string) $run['provider'], $accountId);
    }

    public static function isConfigured(string $provider): bool
    {
        return (new ProviderAccountService())->isConfigured($provider);
    }

    public static function isConfiguredLegacy(string $provider): bool
    {
        return match (strtolower($provider)) {
            'timeweb' => (Env::get('TIMEWEB_API_TOKEN') ?? '') !== '',
            'selectel' => (Env::get('SELECTEL_USERNAME') ?? '') !== ''
                && (Env::get('SELECTEL_PASSWORD') ?? '') !== ''
                && (Env::get('SELECTEL_AUTH_URL') ?? '') !== ''
                && (Env::get('SELECTEL_FLAVOR_ID') ?? '') !== ''
                && (Env::get('SELECTEL_IMAGE_ID') ?? '') !== ''
                && (Env::get('SELECTEL_NETWORK_ID') ?? '') !== '',
            'yandex' => ((Env::get('YANDEX_SA_KEY_JSON') ?? '') !== ''
                    || ((Env::get('YANDEX_SA_ID') ?? '') !== ''
                        && (Env::get('YANDEX_SA_KEY_ID') ?? '') !== ''
                        && (Env::get('YANDEX_SA_PRIVATE_KEY') ?? '') !== ''))
                && (Env::get('YANDEX_FOLDER_ID') ?? '') !== ''
                && (Env::get('YANDEX_SUBNET_ID') ?? '') !== '',
            default => false,
        };
    }
}
