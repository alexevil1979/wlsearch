<?php

declare(strict_types=1);

namespace Wlsearch\Support;

final class FileLog
{
    public static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function write(string $channel, string $message, array $context = []): void
    {
        $channel = preg_replace('/[^a-z0-9_\-]/i', '', $channel) ?: 'app';
        $line = '[' . date('c') . "] {$message}";
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }
        @file_put_contents(self::dir() . '/' . $channel . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<string> */
    public static function channels(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.log') ?: [] as $f) {
            $out[] = basename($f, '.log');
        }
        sort($out);
        return $out;
    }

    /**
     * Last N lines of a channel log (raw text).
     */
    public static function tail(string $channel, int $lines = 200, ?string $contains = null): string
    {
        $channel = preg_replace('/[^a-z0-9_\-]/i', '', $channel) ?: 'app';
        $path = self::dir() . '/' . $channel . '.log';
        if (!is_file($path)) {
            return '';
        }
        $lines = max(1, min(2000, $lines));
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        $read = min($size, 512 * 1024);
        fseek($fh, -$read, SEEK_END);
        $blob = stream_get_contents($fh) ?: '';
        fclose($fh);
        $all = preg_split("/\r\n|\n|\r/", $blob) ?: [];
        if ($contains !== null && $contains !== '') {
            $all = array_values(array_filter(
                $all,
                static fn (string $l): bool => str_contains($l, $contains)
            ));
        }
        if (count($all) > $lines) {
            $all = array_slice($all, -$lines);
        }
        return implode("\n", $all);
    }
}
