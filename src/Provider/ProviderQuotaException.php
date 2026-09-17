<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

/** Квота/rate-limit провайдера (Yandex 429) — create нужно отложить, не ERROR. */
final class ProviderQuotaException extends \RuntimeException
{
}
