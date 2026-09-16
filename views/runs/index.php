<?php
/** @var list<array<string,mixed>> $runs */
/** @var string $csrf */
use Wlsearch\Support\View;

$badgeClass = static function (string $state): string {
    return match ($state) {
        'PASS', 'KEEP' => 'badge-ok',
        'FAIL_BS', 'FAIL_CONTROL', 'ERROR', 'DESTROYED' => 'badge-err',
        'BS_CHECK', 'CONTROL_CHECK', 'BOOTSTRAPPING', 'ORDERING' => 'badge-warn',
        'SKIPPED' => '',
        default => '',
    };
};
$fmtDt = static function (?string $dt): string {
    if ($dt === null || $dt === '') {
        return '—';
    }
    $t = strtotime($dt);
    return $t !== false ? date('d.m.Y H:i', $t) : $dt;
};
?>
<div class="page-head">
    <div>
        <h1>Runs</h1>
        <p class="muted">ORDERING → … → CONTROL_CHECK → BS_CHECK. Destroy при FAIL (если не keep).</p>
    </div>
    <a class="btn" href="/runs/new">Запустить прогон</a>
</div>

<div class="card" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;justify-content:space-between;padding:0.85rem 1rem">
    <p class="muted" style="margin:0">Остановить ORDERING (SKIPPED). Живые в работе → KEEP, без destroy. PASS/KEEP не трогает.</p>
    <form method="post" action="/runs/stop-queue" style="margin:0"
          onsubmit="return confirm('Остановить очередь?\nORDERING → SKIPPED\nВ работе → KEEP (VPS не удаляем)\nPASS/KEEP без изменений')">
        <?= $csrf ?>
        <button class="btn btn-danger" type="submit" style="min-width:12rem">Остановить очередь</button>
    </form>
</div>

<div class="card table-wrap">
    <?php if ($runs === []): ?>
        <p class="muted" style="margin:0">Пока нет прогонов.</p>
    <?php else: ?>
        <table class="data">
            <thead>
            <tr>
                <th>ID</th>
                <th>State</th>
                <th>Cloud</th>
                <th>IP</th>
                <th>ASN</th>
                <th>ctrl/bs</th>
                <th>Создан</th>
                <th>Тест</th>
                <th>Ошибка</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <?php
                $id = (int) $r['id'];
                $state = (string) $r['state'];
                $canDestroy = !in_array($state, ['DESTROYED', 'DESTROYING'], true) && !empty($r['provider_server_id']);
                $canKeep = !in_array($state, ['DESTROYED', 'KEEP'], true);
                $canRetryControl = in_array($state, ['FAIL_CONTROL', 'BS_CHECK', 'CONTROL_CHECK', 'PASS', 'FAIL_BS', 'KEEP', 'BOOTSTRAPPING'], true) && !empty($r['ipv4']);
                $canRetryBs = !empty($r['ipv4'])
                    && !in_array($state, ['DESTROYED', 'DESTROYING', 'ORDERING', 'PROVISIONING'], true);
                $probeHint = '';
                if (in_array($state, ['BOOTSTRAPPING', 'CONTROL_CHECK'], true) && !empty($r['ipv4'])) {
                    $probeHint = \Wlsearch\Probe\CloudInitBuilder::oneLiner(
                        (string) ($r['provider'] ?? 'timeweb'),
                        $id
                    );
                }
                $accLabel = '';
                if (!empty($r['account_name'])) {
                    $accLabel = '#' . (int) ($r['provider_account_id'] ?? 0) . ' ' . (string) $r['account_name'];
                } elseif (!empty($r['provider_account_id'])) {
                    $accLabel = '#' . (int) $r['provider_account_id'];
                }
                $ctrl = $r['control_ok'] === null ? '—' : ((int) $r['control_ok'] ? '✓' : '✗');
                $bs = $r['bs_ok'] === null ? '—' : ((int) $r['bs_ok'] ? '✓' : '✗');
                $created = $fmtDt(isset($r['created_at']) ? (string) $r['created_at'] : null);
                $testedRaw = $r['tested_at'] ?? null;
                if (($testedRaw === null || $testedRaw === '')
                    && ($r['bs_ok'] !== null || $r['control_ok'] !== null)
                    && in_array($state, ['PASS', 'FAIL_BS', 'FAIL_CONTROL', 'KEEP', 'DESTROYED', 'ERROR'], true)
                ) {
                    $testedRaw = $r['updated_at'] ?? null;
                }
                $tested = $fmtDt($testedRaw !== null && $testedRaw !== '' ? (string) $testedRaw : null);
                $err = (string) ($r['error_message'] ?? '');
                ?>
                <tr>
                    <td class="cell-narrow">#<?= $id ?></td>
                    <td class="cell-narrow">
                        <div class="cell-stack">
                            <span class="badge <?= $badgeClass($state) ?>"><?= View::e($state) ?></span>
                            <span class="muted" style="font-size:0.72rem"><?= View::e((string) ($r['bs_mode'] ?? 'agent')) ?><?php if (!empty($r['bs_source'])): ?> · <?= View::e((string) $r['bs_source']) ?><?php endif; ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="cell-stack">
                            <span><?= View::e((string) $r['provider']) ?><?php if ($r['region']): ?> <span class="muted"><?= View::e((string) $r['region']) ?></span><?php endif; ?></span>
                            <?php if ($accLabel !== ''): ?>
                                <span class="muted cell-clip" title="<?= View::e($accLabel) ?>"><?= View::e($accLabel) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-narrow">
                        <?php if (!empty($r['ipv4'])): ?>
                            <code><?= View::e((string) $r['ipv4']) ?></code>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted cell-narrow">
                        <?php if ($r['asn']): ?>
                            AS<?= (int) $r['asn'] ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="cell-narrow muted"><?= $ctrl ?>/<?= $bs ?></td>
                    <td class="cell-narrow muted" style="white-space:nowrap;font-size:0.78rem"><?= View::e($created) ?></td>
                    <td class="cell-narrow muted" style="white-space:nowrap;font-size:0.78rem"><?= View::e($tested) ?></td>
                    <td class="cell-error" title="<?= View::e($err) ?>"><?= View::e($err) ?></td>
                    <td class="cell-actions">
                        <div class="actions">
                            <?php if ($canDestroy): ?>
                                <form method="post" action="/runs/<?= $id ?>/destroy" onsubmit="return confirm('Destroy VPS сейчас?')">
                                    <?= $csrf ?>
                                    <button class="btn btn-danger btn-sm" type="submit">destroy</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canKeep): ?>
                                <form method="post" action="/runs/<?= $id ?>/keep">
                                    <?= $csrf ?>
                                    <button class="btn btn-secondary btn-sm" type="submit">keep</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canRetryControl): ?>
                                <form method="post" action="/runs/<?= $id ?>/retry-control">
                                    <?= $csrf ?>
                                    <button class="btn btn-secondary btn-sm" type="submit">ctrl</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canRetryBs): ?>
                                <form method="post" action="/runs/<?= $id ?>/retry-bs">
                                    <?= $csrf ?>
                                    <button class="btn btn-sm" type="submit" title="Повторная проверка BS">BS</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if ($probeHint !== ''): ?>
                <tr>
                    <td colspan="10" style="padding-top:0">
                        <details>
                            <summary class="muted" style="cursor:pointer;font-size:0.85rem">
                                Probe кривой / 502. Одна команда (nginx) на VPS → потом BS
                            </summary>
                            <pre style="white-space:pre-wrap;font-size:0.72rem;max-height:6rem;overflow:auto;margin:0.4rem 0 0"><?= View::e($probeHint) ?></pre>
                        </details>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
