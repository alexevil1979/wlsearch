<?php
/** @var array $stats */
/** @var list<array<string,mixed>> $accountStats */
use Wlsearch\Support\View;

$accountStats = $accountStats ?? [];
?>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">create → control → BS → PASS/inventory или FAIL/destroy.</p>
    </div>
    <div class="actions">
        <a class="btn" href="/runs/new">Запустить прогон</a>
        <a class="btn btn-secondary" href="/runs">Runs</a>
        <a class="btn btn-secondary" href="/accounts">Аккаунты</a>
    </div>
</div>

<div class="grid" style="margin:0 0 1rem">
    <div class="stat">
        <div class="label">Активные runs</div>
        <div class="value"><?= (int) $stats['active_runs'] ?></div>
    </div>
    <div class="stat">
        <div class="label">Очередь tasks</div>
        <div class="value"><?= (int) $stats['queued_tasks'] ?></div>
    </div>
    <div class="stat">
        <div class="label">Online agents</div>
        <div class="value"><?= (int) $stats['online_agents'] ?></div>
    </div>
    <div class="stat">
        <div class="label">IP выдано сегодня</div>
        <div class="value"><?= (int) $stats['creates_today'] ?> / <?= (int) $stats['max_creates'] ?></div>
    </div>
    <div class="stat">
        <div class="label">Параллель VM</div>
        <div class="value"><?= (int) $stats['max_parallel'] ?></div>
    </div>
    <div class="stat">
        <div class="label">БД</div>
        <div class="value" style="font-size:1.1rem">
            <?php if ($stats['db_ok']): ?>
                <span class="badge badge-ok">online</span>
            <?php else: ?>
                <span class="badge badge-err">offline</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card table-wrap" style="margin-bottom:1rem">
    <h2 style="margin-top:0">По аккаунтам</h2>
    <p class="muted" style="margin:0 0 0.75rem;font-size:0.85rem">
        Отработано = runs с выданным IP. Today — за сегодня.
    </p>
    <?php if ($accountStats === []): ?>
        <p class="muted" style="margin:0">Нет аккаунтов или БД недоступна.</p>
    <?php else: ?>
        <table class="data">
            <thead>
            <tr>
                <th>#</th>
                <th>Аккаунт</th>
                <th>Provider</th>
                <th></th>
                <th title="Runs с IP">IP</th>
                <th>Today</th>
                <th>PASS</th>
                <th>KEEP</th>
                <th>FAIL_BS</th>
                <th>FAIL_CTRL</th>
                <th>Live</th>
                <th>Dead</th>
                <th>Skip</th>
                <th>Всего</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($accountStats as $a): ?>
                <?php
                $en = (int) ($a['enabled'] ?? 0) === 1;
                $name = (string) ($a['name'] ?? '');
                ?>
                <tr>
                    <td class="cell-narrow muted">#<?= (int) $a['id'] ?></td>
                    <td><?= View::e($name !== '' ? $name : '—') ?></td>
                    <td class="muted cell-narrow"><?= View::e((string) ($a['provider'] ?? '')) ?></td>
                    <td class="cell-narrow">
                        <?php if ($en): ?>
                            <span class="badge badge-ok">on</span>
                        <?php else: ?>
                            <span class="badge">off</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-narrow"><strong><?= (int) ($a['with_ip'] ?? 0) ?></strong></td>
                    <td class="cell-narrow"><?= (int) ($a['today'] ?? 0) ?></td>
                    <td class="cell-narrow"><span class="badge badge-ok"><?= (int) ($a['pass'] ?? 0) ?></span></td>
                    <td class="cell-narrow"><?= (int) ($a['keep'] ?? 0) ?></td>
                    <td class="cell-narrow"><span class="badge badge-err"><?= (int) ($a['fail_bs'] ?? 0) ?></span></td>
                    <td class="cell-narrow"><?= (int) ($a['fail_ctrl'] ?? 0) ?></td>
                    <td class="cell-narrow"><?= (int) ($a['live'] ?? 0) ?></td>
                    <td class="cell-narrow muted"><?= (int) ($a['dead'] ?? 0) ?></td>
                    <td class="cell-narrow muted"><?= (int) ($a['skipped'] ?? 0) ?></td>
                    <td class="cell-narrow muted"><?= (int) ($a['total'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Критерий PASS</h2>
    <p class="muted" style="margin:0">
        BS: bsbord TCP с БС (dpi=on) на голый IP — как зелёная точка в «МОИ ПРОВЕРКИ».
    </p>
</div>
