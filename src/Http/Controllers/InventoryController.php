<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Inventory\InventoryService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class InventoryController
{
    public function index(): void
    {
        $this->auth();
        View::render('inventory/index', [
            'title' => 'Inventory PASS',
            'user' => AuthService::user(),
            'items' => (new InventoryService())->listActive(),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'inventory',
        ]);
    }

    public function retire(string $id): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /inventory');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        try {
            (new InventoryService())->retire((int) $id, $actor, $notes !== '' ? $notes : null);
            Flash::set('ok', 'IP помечен как retired.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /inventory');
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
