<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class AuthController
{
    public function showLogin(): void
    {
        if (AuthService::check()) {
            header('Location: /dashboard');
            exit;
        }

        View::render('auth/login', [
            'title' => 'Вход',
            'flash' => Flash::pull(),
            'locked' => AuthService::isLockedOut(),
            'lockSeconds' => AuthService::lockRemainingSeconds(),
            'csrf' => Csrf::field(),
        ], null);
    }

    public function login(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF-токен. Обновите страницу.');
            header('Location: /login');
            exit;
        }

        if (AuthService::isLockedOut()) {
            $sec = AuthService::lockRemainingSeconds();
            Flash::set('error', "Слишком много попыток. Подождите {$sec} сек.");
            header('Location: /login');
            exit;
        }

        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            Flash::set('error', 'Укажите логин и пароль.');
            header('Location: /login');
            exit;
        }

        if (!AuthService::attempt($login, $password)) {
            if (AuthService::isLockedOut()) {
                Flash::set('error', 'Аккаунт временно заблокирован из‑за неудачных попыток.');
            } else {
                Flash::set('error', 'Неверный логин или пароль.');
            }
            header('Location: /login');
            exit;
        }

        header('Location: /dashboard');
        exit;
    }

    public function logout(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Bad CSRF';
            return;
        }
        AuthService::logout();
        header('Location: /login');
        exit;
    }
}
