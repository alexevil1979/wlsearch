<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Probe\BsbordClient;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Database;
use Wlsearch\Support\Env;
use Wlsearch\Support\Flash;
use Wlsearch\Support\Settings;
use Wlsearch\Support\View;

final class SettingsController
{
    private const EDITABLE = [
        'MAX_PARALLEL_VMS',
        'MAX_CREATES_PER_DAY',
        'CREATES_PER_ACCOUNT_DAY',
        'MAX_DAILY_SPEND_RUB',
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_CHAT_ID',
        'TELEGRAM_PROXY',
        'BS_TASK_TTL_SEC',
        'PROVISION_TIMEOUT_SEC',
        'BOOTSTRAP_TIMEOUT_SEC',
        'CONTROL_CHECK_TIMEOUT_SEC',
        'BSBORD_API_TOKEN',
        'BSBORD_OPERATORS',
        'BSBORD_MIN_PASS',
        'BS_MODE_DEFAULT',
    ];

    public function index(): void
    {
        $this->auth();
        $values = [];
        foreach (self::EDITABLE as $key) {
            $values[$key] = Settings::get($key, Env::get($key, ''));
        }

        $bsUnits = [];
        $bsError = null;
        $selected = array_filter(array_map('trim', explode(',', (string) ($values['BSBORD_OPERATORS'] ?? ''))));
        try {
            $client = new BsbordClient();
            if ($client->isConfigured()) {
                $bsUnits = $client->listOperators('on');
            }
        } catch (\Throwable $e) {
            $bsError = $e->getMessage();
        }

        // group by region_code
        $byRegion = [];
        foreach ($bsUnits as $u) {
            $rc = $u['region_code'] !== '' ? $u['region_code'] : 'other';
            $byRegion[$rc]['title'] = $u['region'] !== '' ? $u['region'] : strtoupper($rc);
            $byRegion[$rc]['units'][] = $u;
        }
        ksort($byRegion);

        View::render('settings/index', [
            'title' => 'Лимиты и настройки',
            'user' => AuthService::user(),
            'values' => $values,
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'settings',
            'bsByRegion' => $byRegion,
            'bsSelected' => $selected,
            'bsError' => $bsError,
            'bsConfigured' => (new BsbordClient())->isConfigured(),
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

        // Operators from checkboxes
        if (isset($_POST['bs_op']) && is_array($_POST['bs_op'])) {
            $ops = array_values(array_filter(array_map('strval', $_POST['bs_op'])));
            // discard dpi=off if somehow posted
            $ops = array_values(array_filter($ops, static fn (string $k): bool => !str_ends_with(strtolower($k), '|off')));
            $_POST['BSBORD_OPERATORS'] = implode(',', $ops);
        } elseif (array_key_exists('bs_ops_cleared', $_POST)) {
            $_POST['BSBORD_OPERATORS'] = '';
        }

        $changed = [];
        foreach (self::EDITABLE as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $val = trim((string) $_POST[$key]);
            $stmt->execute([$key, $val]);
            $secretKeys = ['BSBORD_API_TOKEN', 'TELEGRAM_BOT_TOKEN'];
            $changed[$key] = in_array($key, $secretKeys, true) && $val !== '' ? '(set)' : $val;
        }

        Settings::resetCache();
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        Audit::log($actor, 'settings.update', 'settings', null, $changed);
        Flash::set('ok', 'Настройки сохранены. BS-проверка: только каналы «БС» (dpi=on).');
        header('Location: /settings');
        exit;
    }

    public function selectCfoTrio(): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /settings');
            exit;
        }
        try {
            $client = new BsbordClient();
            $units = $client->listOperators('on');
            $wanted = ['megafon', 'mts', 'beeline'];
            $keys = [];
            foreach ($units as $u) {
                $op = strtolower($u['operator']);
                $rc = $u['region_code'];
                $reg = mb_strtolower($u['region']);
                if (!in_array($op, $wanted, true)) {
                    continue;
                }
                if ($rc === 'cfo' || str_contains($reg, 'цфо') || str_contains($reg, 'моск')) {
                    $keys[] = $u['op_key'];
                }
            }
            if ($keys === []) {
                throw new \RuntimeException('Не найдены МегаФон/МТС/Билайн ЦФО с БС (dpi=on)');
            }
            $pdo = Database::pdo();
            $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (\'BSBORD_OPERATORS\', ?, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
            )->execute([implode(',', array_unique($keys))]);
            Settings::resetCache();
            Flash::set('ok', 'Выбрано БС ЦФО: ' . implode(', ', $keys));
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
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
