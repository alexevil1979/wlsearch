<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Support\Database;
use Wlsearch\Support\Env;

final class HealthController
{
    public function index(): void
    {
        $dbOk = Database::tryPdo() !== null;
        $payload = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'app' => Env::get('APP_NAME', 'wlsearch'),
            'time' => gmdate('c'),
            'db' => $dbOk ? 'up' : 'down',
            'phase' => 0,
        ];

        http_response_code($dbOk ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
