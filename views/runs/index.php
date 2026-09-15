<?php
/** @var list<array<string,mixed>> $runs */
/** @var string $csrf */
use Wlsearch\Support\View;

$badgeClass = static function (string $state): string {
    return match ($state) {
        'PASS', 'KEEP' => 'badge-ok',
        'FAIL_BS', 'FAIL_CONTROL', 'ERROR', 'DESTROYED' => 'badge-err',
        'BS_CHECK', 'CONTROL_CHECK', 'BOOTSTRAPPING' => 'badge-warn',
        default => '',
    };
};
?>
<h1>Runs</h1>
<p class="muted">State machine: ORDERING → … → CONTROL_CHECK → BS_CHECK. Destroy при FAIL (если не keep).</p>

<p style="margin-bottom:1rem">
    <a class="btn" href="/runs/new">Запустить прогон</a>
</p>

<div class="card table-wrap">
    <?php if ($runs === []): ?>
        <p class="muted" style="margin:0">Пока нет прогонов.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>State</th>
                <th>BS</th>
                <th>Provider</th>
                <th>IP</th>
                <th>ASN</th>
                <th>ctrl</th>
                <th>bs</th>
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
                $canRetryControl = in_array($state, ['FAIL_CONTROL', 'BS_CHECK', 'CONTROL_CHECK'], true) && !empty($r['ipv4']);
                $canRetryBs = in_array($state, ['FAIL_BS', 'BS_CHECK'], true) && (int) ($r['control_ok'] ?? 0) === 1;
                ?>
                <tr>
                    <td>#<?= $id ?></td>
                    <td><span class="badge <?= $badgeClass($state) ?>"><?= View::e($state) ?></span></td>
                    <td class="muted">
                        <?= View::e((string) ($r['bs_mode'] ?? 'agent')) ?>
                        <?php if (!empty($r['bs_source'])): ?>
                            <br><span class="badge badge-ok"><?= View::e((string) $r['bs_source']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= View::e((string) $r['provider']) ?><?php if ($r['region']): ?><br><span class="muted"><?= View::e((string) $r['region']) ?></span><?php endif; ?></td>
                    <td>
                        <?php if (!empty($r['ipv4'])): ?>
                            <code><?= View::e((string) $r['ipv4']) ?></code>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted">
                        <?php if ($r['asn']): ?>
                            AS<?= (int) $r['asn'] ?>
                            <?php if ($r['asn_org']): ?><br><?= View::e((string) $r['asn_org']) ?><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= $r['control_ok'] === null ? '—' : ((int) $r['control_ok'] ? '✓' : '✗') ?></td>
                    <td><?= $r['bs_ok'] === null ? '—' : ((int) $r['bs_ok'] ? '✓' : '✗') ?></td>
                    <td class="muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= View::e((string) ($r['error_message'] ?? '')) ?>">
                        <?= View::e(mb_substr((string) ($r['error_message'] ?? ''), 0, 60)) ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($canDestroy): ?>
                            <form method="post" action="/runs/<?= $id ?>/destroy" style="display:inline" onsubmit="return confirm('Destroy VPS сейчас?')">
                                <?= $csrf ?>
                                <button class="btn btn-danger" type="submit" style="padding:0.25rem 0.45rem;font-size:0.78rem">destroy</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canKeep): ?>
                            <form method="post" action="/runs/<?= $id ?>/keep" style="display:inline">
                                <?= $csrf ?>
                                <button class="btn btn-secondary" type="submit" style="padding:0.25rem 0.45rem;font-size:0.78rem">keep</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canRetryControl): ?>
                            <form method="post" action="/runs/<?= $id ?>/retry-control" style="display:inline">
                                <?= $csrf ?>
                                <button class="btn btn-secondary" type="submit" style="padding:0.25rem 0.45rem;font-size:0.78rem">retry ctrl</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canRetryBs): ?>
                            <form method="post" action="/runs/<?= $id ?>/retry-bs" style="display:inline">
                                <?= $csrf ?>
                                <button class="btn btn-secondary" type="submit" style="padding:0.25rem 0.45rem;font-size:0.78rem">retry BS</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
