<?php
/** @var list<array<string,mixed>> $items */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<h1>Checked IPs</h1>
<p class="muted">Уже проверенные адреса. При повторной выдаче того же IP VPS сразу destroy (не тратим control/BS).</p>

<div class="card table-wrap">
    <?php if ($items === []): ?>
        <p class="muted" style="margin:0">Пока пусто — после migrate появятся IP из прошлых runs.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>IP</th>
                <th>Verdict</th>
                <th>Provider</th>
                <th>ASN</th>
                <th>Run</th>
                <th>Detail</th>
                <th>Updated</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <?php
                $v = (string) ($it['verdict'] ?? '');
                $badge = $v === 'pass' ? 'badge-ok' : 'badge-err';
                ?>
                <tr>
                    <td><code><?= View::e((string) $it['ipv4']) ?></code></td>
                    <td><span class="badge <?= $badge ?>"><?= View::e($v) ?></span></td>
                    <td class="muted"><?= View::e((string) ($it['provider'] ?? '—')) ?></td>
                    <td class="muted"><?= $it['asn'] ? 'AS' . (int) $it['asn'] : '—' ?></td>
                    <td class="muted"><?= $it['run_id'] ? '#' . (int) $it['run_id'] : '—' ?></td>
                    <td class="muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= View::e((string) ($it['detail'] ?? '')) ?>">
                        <?= View::e(mb_substr((string) ($it['detail'] ?? ''), 0, 80)) ?>
                    </td>
                    <td class="muted"><?= View::e((string) ($it['updated_at'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/checked-ips/<?= rawurlencode((string) $it['ipv4']) ?>/delete" style="display:inline" onsubmit="return confirm('Удалить из кэша?')">
                            <?= $csrf ?>
                            <button class="btn btn-secondary" type="submit" style="padding:0.25rem 0.45rem;font-size:0.78rem">forget</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
