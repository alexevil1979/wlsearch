<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Provider\ProviderAccountService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class AccountsController
{
    public function index(): void
    {
        $this->auth();
        $svc = new ProviderAccountService();
        $accounts = $svc->listAll();
        $byProvider = ['timeweb' => [], 'selectel' => []];
        foreach ($accounts as $a) {
            $p = (string) $a['provider'];
            if (!isset($byProvider[$p])) {
                $byProvider[$p] = [];
            }
            $creds = json_decode((string) $a['credentials_json'], true);
            $cfg = json_decode((string) $a['config_json'], true);
            $a['_creds'] = is_array($creds) ? ProviderAccountService::maskCredentials($creds) : [];
            $a['_config'] = is_array($cfg) ? $cfg : [];
            $byProvider[$p][] = $a;
        }

        $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $editUsername = null;
        if ($editId > 0) {
            $raw = $svc->get($editId);
            if ($raw) {
                $c = json_decode((string) $raw['credentials_json'], true);
                if (is_array($c)) {
                    $editUsername = (string) ($c['SELECTEL_USERNAME'] ?? '');
                }
            }
        }

        View::render('accounts/index', [
            'title' => 'Аккаунты провайдеров',
            'user' => AuthService::user(),
            'byProvider' => $byProvider,
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'accounts',
            'editId' => $editId,
            'editUsername' => $editUsername,
        ]);
    }

    public function saveEnabled(): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /accounts');
            exit;
        }
        $provider = strtolower(trim((string) ($_POST['provider'] ?? '')));
        $ids = isset($_POST['acc']) && is_array($_POST['acc'])
            ? array_map('intval', $_POST['acc'])
            : [];
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            (new ProviderAccountService())->setEnabledMany($provider, $ids, $actor);
            Flash::set('ok', 'Включённые аккаунты ' . $provider . ' сохранены (' . count($ids) . ').');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /accounts#' . $provider);
        exit;
    }

    public function create(): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /accounts');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            [$provider, $name, $creds, $config, $enabled] = $this->parseForm();
            $id = (new ProviderAccountService())->create($provider, $name, $creds, $config, $enabled, $actor);
            Flash::set('ok', "Аккаунт #{$id} создан.");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /accounts');
        exit;
    }

    public function update(string $id): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /accounts');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            [, $name, $creds, $config, $enabled] = $this->parseForm();
            (new ProviderAccountService())->update((int) $id, $name, $creds, $config, $enabled, $actor);
            Flash::set('ok', "Аккаунт #{$id} обновлён.");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /accounts');
        exit;
    }

    public function delete(string $id): void
    {
        $this->auth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF.');
            header('Location: /accounts');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            (new ProviderAccountService())->delete((int) $id, $actor);
            Flash::set('ok', "Аккаунт #{$id} удалён.");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /accounts');
        exit;
    }

    /**
     * @return array{0:string,1:string,2:array<string,string>,3:array<string,string>,4:bool}
     */
    private function parseForm(): array
    {
        $provider = strtolower(trim((string) ($_POST['provider'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $enabled = !empty($_POST['enabled']);

        $creds = [];
        $config = [];
        if ($provider === 'timeweb') {
            $creds['TIMEWEB_API_TOKEN'] = trim((string) ($_POST['TIMEWEB_API_TOKEN'] ?? ''));
            foreach ([
                'TIMEWEB_API_BASE', 'TIMEWEB_PRESET_ID', 'TIMEWEB_OS_ID', 'TIMEWEB_AVAILABILITY_ZONE',
                'TIMEWEB_BANDWIDTH', 'TIMEWEB_PROJECT_ID', 'TIMEWEB_PRESET_COST_RUB',
                'TIMEWEB_ENSURE_IPV4', 'TIMEWEB_FLOATING_IP_ID', 'TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY',
                'TIMEWEB_CONFIGURATOR_ID', 'TIMEWEB_CPU', 'TIMEWEB_GPU', 'TIMEWEB_RAM_GB', 'TIMEWEB_DISK_GB',
            ] as $k) {
                if (!array_key_exists($k, $_POST)) {
                    continue;
                }
                $config[$k] = trim((string) $_POST[$k]);
            }
            if (($config['TIMEWEB_API_BASE'] ?? '') === '') {
                $config['TIMEWEB_API_BASE'] = 'https://api.timeweb.cloud/api/v1';
            }
        } elseif ($provider === 'selectel') {
            $creds['SELECTEL_USERNAME'] = trim((string) ($_POST['SELECTEL_USERNAME'] ?? ''));
            $creds['SELECTEL_PASSWORD'] = trim((string) ($_POST['SELECTEL_PASSWORD'] ?? ''));
            foreach ([
                'SELECTEL_AUTH_URL', 'SELECTEL_PROJECT_ID', 'SELECTEL_PROJECT_NAME',
                'SELECTEL_USER_DOMAIN_NAME', 'SELECTEL_PROJECT_DOMAIN_NAME',
                'SELECTEL_FLAVOR_ID', 'SELECTEL_IMAGE_ID', 'SELECTEL_NETWORK_ID',
                'SELECTEL_EXTERNAL_NET_ID', 'SELECTEL_REGION', 'SELECTEL_PRESET_COST_RUB',
            ] as $k) {
                if (!array_key_exists($k, $_POST)) {
                    continue;
                }
                $config[$k] = trim((string) $_POST[$k]);
            }
        } else {
            throw new \InvalidArgumentException('provider: timeweb|selectel');
        }

        return [$provider, $name, $creds, $config, $enabled];
    }

    private function auth(): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }
    }
}
