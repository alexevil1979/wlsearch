<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

/** Недостаточно запаса Timeweb (~30д) — create дал бы no_paid. */
final class BalanceShortException extends \RuntimeException
{
}
