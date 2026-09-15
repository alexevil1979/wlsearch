<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Database;
use Wlsearch\Support\Flash;
use Wlsearch\Support\Settings;
use Wlsearch\Support\View;

final class SettingsController
{
    private const EDITABLE = [
        'MAX_PARALLEL_VMS',
        'MAX_CREATES_PER_DAY',
        'MAX_DAILY_SPEND_RUB',
        'TELEGRAM_CHAT_ID',
        'BS_TASK_TTL_SEC',
        'PROVISION_TIMEOUT_SEC',
        'BOOTSTRAP_TIMEOUT_SEC',
        'CONTROL_CHECK_TIMEOUT_SEC',
        'BSBORD_API_TOKEN',
        'BSBORD_OPERATORS',
        'BS_MODE_DEFAULT',
    ];

    public function index(): void
    {
        $this->auth();
        $values = [];
        foreach (self::EDITABLE as $key) {
            $values[$key] = Settings::get($key, '');
        }
        View::render('settings/index', [
            'title' => 'Лимиты и настройки',
            'user' => AuthService::user(),
            'values' => $values,
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'settings',
        ]);
    }

    public function save(): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /settings');
            exit;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        $changed = [];
        foreach (self::EDITABLE as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $val = trim((string) $_POST[$key]);
            $stmt->execute([$key, $val]);
            $changed[$key] = $val;
        }

        Settings::resetCache();
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        Audit::log($actor, 'settings.update', 'settings', null, $changed);
        Flash::set('ok', 'Настройки сохранены.');
        header('Location: /settings');
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
