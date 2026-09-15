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
?>
<div class="page-head">
    <div>
        <h1>Runs</h1>
        <p class="muted">ORDERING → … → CONTROL_CHECK → BS_CHECK. Destroy при FAIL (если не keep).</p>
    </div>
    <a class="btn" href="/runs/new">Запустить прогон</a>
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
                $canRetryControl = in_array($state, ['FAIL_CONTROL', 'BS_CHECK', 'CONTROL_CHECK', 'PASS', 'FAIL_BS', 'KEEP'], true) && !empty($r['ipv4']);
                $canRetryBs = !empty($r['ipv4'])
                    && !in_array($state, ['DESTROYED', 'DESTROYING', 'ORDERING', 'PROVISIONING', 'BOOTSTRAPPING'], true);
                $err = (string) ($r['error_message'] ?? '');
                $accLabel = '';
                if (!empty($r['account_name'])) {
                    $accLabel = '#' . (int) ($r['provider_account_id'] ?? 0) . ' ' . (string) $r['account_name'];
                } elseif (!empty($r['provider_account_id'])) {
                    $accLabel = '#' . (int) $r['provider_account_id'];
                }
                $ctrl = $r['control_ok'] === null ? '—' : ((int) $r['control_ok'] ? '✓' : '✗');
                $bs = $r['bs_ok'] === null ? '—' : ((int) $r['bs_ok'] ? '✓' : '✗');
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
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
