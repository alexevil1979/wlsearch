<?php

declare(strict_types=1);

namespace Wlsearch\Support;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): void
    {
        $root = dirname(__DIR__, 2) . '/views';
        $file = $root . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutFile = $root . '/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            throw new \RuntimeException("Layout not found: {$layout}");
        }
        require $layoutFile;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
