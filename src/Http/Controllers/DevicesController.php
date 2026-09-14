<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Device\DeviceService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class DevicesController
{
    public function index(): void
    {
        $this->auth();
        View::render('devices/index', [
            'title' => 'Devices / agents',
            'user' => AuthService::user(),
            'devices' => (new DeviceService())->listAll(),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'devices',
            'newToken' => $_SESSION['_new_device_token'] ?? null,
        ]);
        unset($_SESSION['_new_device_token']);
    }

    public function create(): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /devices');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            $result = (new DeviceService())->create(
                trim((string) ($_POST['name'] ?? '')),
                strtolower(trim((string) ($_POST['operator'] ?? 'other'))),
                $actor,
            );
            $_SESSION['_new_device_token'] = $result['token'];
            Flash::set('ok', 'Агент создан. Скопируйте token — он показывается один раз.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /devices');
        exit;
    }

    public function revoke(string $id): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /devices');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        (new DeviceService())->revoke((int) $id, $actor);
        Flash::set('ok', 'Token отозван.');
        header('Location: /devices');
        exit;
    }

    private function auth(): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }
    }
}
