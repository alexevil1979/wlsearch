<?php

declare(strict_types=1);

namespace Wlsearch\Support;

final class Flash
{
    private const KEY = '_flash';

    public static function set(string $type, string $message): void
    {
        $_SESSION[self::KEY] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    public static function pull(): ?array
    {
        if (empty($_SESSION[self::KEY])) {
            return null;
        }
        $flash = $_SESSION[self::KEY];
        unset($_SESSION[self::KEY]);
        return $flash;
    }
}
