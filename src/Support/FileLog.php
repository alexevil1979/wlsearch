<?php

declare(strict_types=1);

namespace Wlsearch\Support;

final class FileLog
{
    public static function write(string $channel, string $message, array $context = []): void
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = '[' . date('c') . "] {$message}";
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }
        @file_put_contents($dir . '/' . $channel . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
