<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use PDO;
use Wlsearch\Auth\AuthService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Database;
use Wlsearch\Support\View;

final class AuditController
{
    public function index(): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }

        $rows = [];
        $pdo = Database::tryPdo();
        if ($pdo) {
            $rows = $pdo->query(
                'SELECT * FROM audit_log ORDER BY id DESC LIMIT 200'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        View::render('logs/index', [
            'title' => 'Логи / audit',
            'user' => AuthService::user(),
            'rows' => $rows,
            'csrf' => Csrf::field(),
            'nav' => 'logs',
        ]);
    }
}
