<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Provider\ProviderFactory;
use Wlsearch\Run\RunService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Env;
use Wlsearch\Support\Flash;
use Wlsearch\Support\Settings;
use Wlsearch\Support\View;

final class RunsController
{
    public function index(): void
    {
        $this->requireAuth();
        $service = new RunService();
        View::render('runs/index', [
            'title' => 'Runs',
            'user' => AuthService::user(),
            'runs' => $service->listRecent(150),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'runs',
        ]);
    }

    public function createForm(): void
    {
        $this->requireAuth();
        View::render('runs/new', [
            'title' => 'Запуск прогона',
            'user' => AuthService::user(),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'runs',
            'timewebConfigured' => ProviderFactory::isConfigured('timeweb'),
            'defaultRegion' => Env::get('TIMEWEB_AVAILABILITY_ZONE', 'spb-3'),
            'maxParallel' => Settings::int('MAX_PARALLEL_VMS', 3),
            'maxCreates' => Settings::int('MAX_CREATES_PER_DAY', 20),
        ]);
    }

    public function create(): void
    {
        $this->requireAuth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF-токен.');
            header('Location: /runs/new');
            exit;
        }

        $provider = strtolower(trim((string) ($_POST['provider'] ?? 'timeweb')));
        $region = trim((string) ($_POST['region'] ?? ''));
        $count = (int) ($_POST['count'] ?? 1);
        $keepOnFail = !empty($_POST['keep_on_fail']);
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $actor = (string) (AuthService::user()['login'] ?? 'admin');

        try {
            $ids = (new RunService())->createRuns(
                $provider,
                $region !== '' ? $region : null,
                $count,
                $keepOnFail,
                $comment !== '' ? $comment : null,
                $actor,
            );
            Flash::set('ok', 'Создано run: #' . implode(', #', $ids) . '. Worker подхватит в течение минуты.');
            header('Location: /runs');
            exit;
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
            header('Location: /runs/new');
            exit;
        }
    }

    public function destroy(string $id): void
    {
        $this->action((int) $id, 'destroy');
    }

    public function keep(string $id): void
    {
        $this->action((int) $id, 'keep');
    }

    public function retryControl(string $id): void
    {
        $this->action((int) $id, 'retry_control');
    }

    public function retryBs(string $id): void
    {
        $this->action((int) $id, 'retry_bs');
    }

    private function action(int $id, string $action): void
    {
        $this->requireAuth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF-токен.');
            header('Location: /runs');
            exit;
        }

        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        $service = new RunService();

        try {
            match ($action) {
                'destroy' => $service->requestDestroy($id, $actor),
                'keep' => $service->requestKeep($id, $actor),
                'retry_control' => $service->retryControl($id, $actor),
                'retry_bs' => $service->retryBs($id, $actor),
                default => throw new \InvalidArgumentException('unknown action'),
            };
            Flash::set('ok', "Действие {$action} для run #{$id} принято.");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header('Location: /runs');
        exit;
    }

    private function requireAuth(): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }
    }
}
