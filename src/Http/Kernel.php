<?php

declare(strict_types=1);

namespace Wlsearch\Http;

use Wlsearch\Auth\AuthService;
use Wlsearch\Http\Controllers\AccountsController;
use Wlsearch\Http\Controllers\AgentController;
use Wlsearch\Http\Controllers\AuditController;
use Wlsearch\Http\Controllers\AuthController;
use Wlsearch\Http\Controllers\BlacklistController;
use Wlsearch\Http\Controllers\CheckedIpsController;
use Wlsearch\Http\Controllers\DashboardController;
use Wlsearch\Http\Controllers\DevicesController;
use Wlsearch\Http\Controllers\FavoriteSubnetsController;
use Wlsearch\Http\Controllers\HealthController;
use Wlsearch\Http\Controllers\InventoryController;
use Wlsearch\Http\Controllers\RunsController;
use Wlsearch\Http\Controllers\SettingsController;

final class Kernel
{
    public function handle(): void
    {
        AuthService::startSession();

        try {
            (new \Wlsearch\Database\Migrator())->migrateQuiet();
        } catch (\Throwable) {
            // DB may be down; controllers handle their own errors
        }

        $router = new Router();
        $this->registerRoutes($router);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $router->dispatch($method, $uri);
    }

    private function registerRoutes(Router $router): void
    {
        $health = new HealthController();
        $auth = new AuthController();
        $dash = new DashboardController();
        $runs = new RunsController();
        $inventory = new InventoryController();
        $checkedIps = new CheckedIpsController();
        $devices = new DevicesController();
        $settings = new SettingsController();
        $accounts = new AccountsController();
        $blacklist = new BlacklistController();
        $favorites = new FavoriteSubnetsController();
        $audit = new AuditController();
        $agent = new AgentController();

        $router->get('/health', [$health, 'index']);

        $router->get('/', static function () use ($auth, $dash): void {
            if (AuthService::check()) {
                $dash->index();
                return;
            }
            $auth->showLogin();
        });

        $router->get('/login', [$auth, 'showLogin']);
        $router->post('/login', [$auth, 'login']);
        $router->post('/logout', [$auth, 'logout']);

        $router->get('/dashboard', [$dash, 'index']);

        $router->get('/runs', [$runs, 'index']);
        $router->get('/runs/new', [$runs, 'createForm']);
        $router->post('/runs', [$runs, 'create']);
        $router->post('/runs/stop-queue', [$runs, 'stopQueue']);
        $router->post('/runs/{id}/destroy', [$runs, 'destroy']);
        $router->post('/runs/{id}/keep', [$runs, 'keep']);
        $router->post('/runs/{id}/retry-control', [$runs, 'retryControl']);
        $router->post('/runs/{id}/retry-bs', [$runs, 'retryBs']);
        $router->post('/runs/{id}/set-root-password', [$runs, 'setRootPassword']);

        $router->get('/inventory', [$inventory, 'index']);
        $router->post('/inventory/{id}/retire', [$inventory, 'retire']);

        $router->get('/checked-ips', [$checkedIps, 'index']);
        $router->post('/checked-ips/{ipv4}/delete', [$checkedIps, 'delete']);

        $router->get('/devices', [$devices, 'index']);
        $router->post('/devices', [$devices, 'create']);
        $router->post('/devices/{id}/revoke', [$devices, 'revoke']);

        $router->get('/accounts', [$accounts, 'index']);
        $router->post('/accounts', [$accounts, 'create']);
        $router->post('/accounts/enabled', [$accounts, 'saveEnabled']);
        $router->post('/accounts/yandex-subnets', [$accounts, 'yandexSubnets']);
        $router->post('/accounts/{id}', [$accounts, 'update']);
        $router->post('/accounts/{id}/delete', [$accounts, 'delete']);

        $router->get('/settings', [$settings, 'index']);
        $router->post('/settings', [$settings, 'save']);
        $router->post('/settings/bsbord-cfo', [$settings, 'selectCfoTrio']);

        $router->get('/logs', [$audit, 'index']);

        $router->get('/blacklist', [$blacklist, 'index']);
        $router->post('/blacklist', [$blacklist, 'create']);
        $router->post('/blacklist/{id}/delete', [$blacklist, 'delete']);

        $router->get('/favorites', [$favorites, 'index']);
        $router->post('/favorites', [$favorites, 'create']);
        $router->post('/favorites/{id}/delete', [$favorites, 'delete']);

        $router->get('/api/v1/agent/tasks/next', [$agent, 'nextTask']);
        $router->post('/api/v1/agent/tasks/{id}/result', [$agent, 'result']);
        $router->post('/api/v1/agent/heartbeat', [$agent, 'heartbeat']);
    }
}
