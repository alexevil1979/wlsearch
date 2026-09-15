<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\CheckedIp\CheckedIpService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class CheckedIpsController
{
    public function index(): void
    {
        $this->requireAuth();
        View::render('checked_ips/index', [
            'title' => 'Checked IPs',
            'user' => AuthService::user(),
            'items' => (new CheckedIpService())->listRecent(300),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'checked',
        ]);
    }

    public function delete(string $ipv4): void
    {
        $this->requireAuth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF-токен.');
            header('Location: /checked-ips');
            exit;
        }
        (new CheckedIpService())->delete(urldecode($ipv4));
        Flash::set('ok', 'IP удалён из checked_ips — можно проверить снова.');
        header('Location: /checked-ips');
        exit;
    }

    private function requireAuth(): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }
    }
}
