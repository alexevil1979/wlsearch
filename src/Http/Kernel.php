<?php

declare(strict_types=1);

namespace Wlsearch\Http;

use Wlsearch\Auth\AuthService;
use Wlsearch\Http\Controllers\AuthController;
use Wlsearch\Http\Controllers\DashboardController;
use Wlsearch\Http\Controllers\HealthController;
use Wlsearch\Http\Controllers\RunsController;
use Wlsearch\Http\Controllers\StubController;

final class Kernel
{
    public function handle(): void
    {
        AuthService::startSession();

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
        $stub = new StubController();

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
        $router->post('/runs/{id}/destroy', [$runs, 'destroy']);
        $router->post('/runs/{id}/keep', [$runs, 'keep']);
        $router->post('/runs/{id}/retry-control', [$runs, 'retryControl']);
        $router->post('/runs/{id}/retry-bs', [$runs, 'retryBs']);

        $router->get('/inventory', [$stub, 'inventory']);
        $router->get('/devices', [$stub, 'devices']);
        $router->get('/settings', [$stub, 'settings']);
        $router->get('/logs', [$stub, 'logs']);
        $router->get('/blacklist', [$stub, 'blacklist']);

        // Agent API placeholders (Phase 2)
        $router->get('/api/v1/agent/tasks/next', [$stub, 'apiNotReady']);
        $router->post('/api/v1/agent/tasks/{id}/result', [$stub, 'apiNotReady']);
        $router->post('/api/v1/agent/heartbeat', [$stub, 'apiNotReady']);
    }
}
