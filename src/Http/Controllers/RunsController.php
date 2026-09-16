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
        $live = $service->currentLiveRun();
        $liveProbeLog = '';
        $liveProbeMeta = '';
        if ($live !== null) {
            $rid = (int) $live['id'];
            $liveProbeLog = \Wlsearch\Support\FileLog::tail('probe', 80, '"run_id":' . $rid);
            $meta = json_decode((string) ($live['provider_meta'] ?? ''), true);
            if (is_array($meta) && !empty($meta['last_probe'])) {
                $liveProbeMeta = (string) json_encode($meta['last_probe'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
        }
        View::render('runs/index', [
            'title' => 'Runs',
            'user' => AuthService::user(),
            'runs' => $service->listRecent(150),
            'liveRun' => $live,
            'liveProbeLog' => $liveProbeLog,
            'liveProbeMeta' => $liveProbeMeta,
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'runs',
        ]);
    }

    public function createForm(): void
    {
        $this->requireAuth();
        $accSvc = new \Wlsearch\Provider\ProviderAccountService();
        $runSvc = new RunService();
        $twAccounts = $accSvc->listAll('timeweb');
        $selAccounts = $accSvc->listAll('selectel');
        $ycAccounts = $accSvc->listAll('yandex');
        $yandexConfigured = ProviderFactory::isConfigured('yandex');
        $timewebConfigured = ProviderFactory::isConfigured('timeweb');
        $selectelConfigured = ProviderFactory::isConfigured('selectel');
        // Timeweb на паузе — по умолчанию Yandex, если настроен
        $preferred = $yandexConfigured ? 'yandex' : ($timewebConfigured ? 'timeweb' : 'selectel');
        $prefAccounts = match ($preferred) {
            'yandex' => $ycAccounts,
            'selectel' => $selAccounts,
            default => $twAccounts,
        };
        $prefEnabled = array_values(array_filter($prefAccounts, static fn (array $a): bool => (int) $a['enabled'] === 1));
        $capacity = $runSvc->dailyCreateCapacity($preferred);
        $usage = $runSvc->accountCreateUsage($prefEnabled, $preferred);
        $createsPerAccount = $runSvc->createsPerAccountDay($preferred);
        View::render('runs/new', [
            'title' => 'Запуск прогона',
            'user' => AuthService::user(),
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'runs',
            'timewebConfigured' => $timewebConfigured,
            'selectelConfigured' => $selectelConfigured,
            'yandexConfigured' => $yandexConfigured,
            'preferredProvider' => $preferred,
            'timewebAccounts' => $twAccounts,
            'selectelAccounts' => $selAccounts,
            'yandexAccounts' => $ycAccounts,
            'defaultRegion' => Settings::get('TIMEWEB_AVAILABILITY_ZONE', Env::get('TIMEWEB_AVAILABILITY_ZONE', 'spb-3')),
            'defaultSelectelRegion' => Env::get('SELECTEL_REGION', 'ru-9a'),
            'defaultYandexRegion' => Env::get('YANDEX_ZONE_ID', 'ru-central1-a'),
            'maxParallel' => max(1, Settings::int('MAX_PARALLEL_VMS', 1)),
            'dailyCapacity' => $capacity,
            'accountUsage' => $usage,
            'createsPerAccount' => $createsPerAccount,
            'enabledAccountCount' => count($prefEnabled),
            'bsbordConfigured' => (Env::get('BSBORD_API_TOKEN', '') ?? '') !== ''
                || (Settings::get('BSBORD_API_TOKEN', '') ?? '') !== '',
            'defaultBsMode' => Settings::get('BS_MODE_DEFAULT', Env::get('BS_MODE_DEFAULT', 'bsbord')),
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
        $defaultMode = Settings::get('BS_MODE_DEFAULT', Env::get('BS_MODE_DEFAULT', 'bsbord')) ?? 'bsbord';
        $bsMode = strtolower(trim((string) ($_POST['bs_mode'] ?? $defaultMode)));
        $accountIds = isset($_POST['account_id']) && is_array($_POST['account_id'])
            ? array_values(array_filter(array_map('intval', $_POST['account_id'])))
            : [];
        $stopOnPass = !empty($_POST['stop_on_pass']);
        $actor = (string) (AuthService::user()['login'] ?? 'admin');

        try {
            $ids = (new RunService())->createRuns(
                $provider,
                $region !== '' ? $region : null,
                $count,
                $keepOnFail,
                $comment !== '' ? $comment : null,
                $actor,
                $bsMode,
                $accountIds !== [] ? $accountIds : null,
                $stopOnPass,
            );
            Flash::set('ok', 'В очередь: #' . implode(', #', $ids) . '. По одному VPS; worker подхватит.');
            header('Location: /runs');
            exit;
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
            header('Location: /runs/new');
            exit;
        }
    }

    public function stopQueue(): void
    {
        $this->requireAuth();
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Flash::set('error', 'Неверный CSRF-токен.');
            header('Location: /runs');
            exit;
        }
        $actor = (string) (AuthService::user()['login'] ?? 'admin');
        try {
            $r = (new RunService())->stopAllQueued($actor);
            Flash::set('ok', "Очередь остановлена: SKIPPED={$r['skipped']}, в работе → KEEP={$r['kept']} (без destroy).");
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /runs');
        exit;
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
                'retry_bs' => Flash::set('ok', $service->retryBs($id, $actor)),
                default => throw new \InvalidArgumentException('unknown action'),
            };
            if ($action !== 'retry_bs') {
                Flash::set('ok', "Действие {$action} для run #{$id} принято.");
            }
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
