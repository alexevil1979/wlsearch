<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Blacklist\BlacklistService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class BlacklistController
{
    public function index(): void
    {
        $this->auth();
        View::render('blacklist/index', [
            'title' => 'Blacklist ASN / prefix',
            'user' => AuthService::user(),
            'items' => (new BlacklistService())->listAll(),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'blacklist',
        ]);
    }

    public function create(): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /blacklist');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            (new BlacklistService())->add(
                (string) ($_POST['kind'] ?? ''),
                (string) ($_POST['value'] ?? ''),
                trim((string) ($_POST['reason'] ?? '')) ?: null,
                $actor,
            );
            Flash::set('ok', 'Добавлено в blacklist.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /blacklist');
        exit;
    }

    public function delete(string $id): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /blacklist');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        (new BlacklistService())->delete((int) $id, $actor);
        Flash::set('ok', 'Удалено.');
        header('Location: /blacklist');
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
