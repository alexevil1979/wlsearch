<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\View;

final class StubController
{
    public function runs(): void
    {
        $this->page('runs', 'Runs', 'Список прогонов появится в Phase 1 (Timeweb + state machine).');
    }

    public function runNew(): void
    {
        $this->page('runs', 'Запуск прогона', 'Форма запуска (provider / region / count / keep_on_fail) — Phase 1.');
    }

    public function inventory(): void
    {
        $this->page('inventory', 'Inventory PASS', 'Белые IP появятся после полного цикла BS-проверки (Phase 2).');
    }

    public function devices(): void
    {
        $this->page('devices', 'Devices / agents', 'Управление phone-агентами и токенами — Phase 2.');
    }

    public function settings(): void
    {
        $this->page('settings', 'Лимиты и настройки', 'Редактирование лимитов и Telegram chat — Phase 4 (сейчас значения из .env).');
    }

    public function logs(): void
    {
        $this->page('logs', 'Логи / audit', 'Audit log действий оператора — Phase 4.');
    }

    public function blacklist(): void
    {
        $this->page('blacklist', 'Blacklist ASN / prefix', 'CRUD blacklist — Phase 4.');
    }

    public function apiNotReady(): void
    {
        http_response_code(501);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'not_implemented',
            'message' => 'Agent API будет в Phase 2',
            'phase' => 0,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function page(string $nav, string $title, string $message): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }

        View::render('stub', [
            'title' => $title,
            'user' => AuthService::user(),
            'message' => $message,
            'csrf' => Csrf::field(),
            'nav' => $nav,
        ]);
    }
}
