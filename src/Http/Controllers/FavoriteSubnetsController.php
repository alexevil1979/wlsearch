<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\FavoriteSubnet\FavoriteSubnetService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class FavoriteSubnetsController
{
    public function index(): void
    {
        $this->auth();
        View::render('favorites/index', [
            'title' => 'Избранные подсети',
            'user' => AuthService::user(),
            'items' => (new FavoriteSubnetService())->listAll(),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'favorites',
        ]);
    }

    public function create(): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /favorites');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            (new FavoriteSubnetService())->add(
                (string) ($_POST['cidr'] ?? ''),
                trim((string) ($_POST['note'] ?? '')) ?: null,
                $actor,
            );
            Flash::set('ok', 'Подсеть добавлена.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /favorites');
        exit;
    }

    public function delete(string $id): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /favorites');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        (new FavoriteSubnetService())->delete((int) $id, $actor);
        Flash::set('ok', 'Удалено.');
        header('Location: /favorites');
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
