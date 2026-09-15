<?php
/** @var array $stats */
use Wlsearch\Support\View;
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
        <div class="label">Create сегодня</div>
        <div class="value"><?= (int) $stats['creates_today'] ?> / <?= (int) $stats['max_creates'] ?></div>
    </div>
    <div class="stat">
        <div class="label">MAX_PARALLEL_VMS</div>
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

<div class="card">
    <h2>Критерий PASS</h2>
    <p class="muted" style="margin:0">
        control_ok ∧ bs_ok ∧ cellular ∧ маркер <code>WL_PROBE_OK</code>.
        BS: bsbord (dpi=on) и/или Android SIM.
    </p>
</div>
