<?php
/** @var list<array<string,mixed>> $items */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<h1>Inventory PASS</h1>
<p class="muted">Белые IP: control_ok ∧ bs_ok ∧ cellular ∧ маркер WL_PROBE_OK.</p>

<div class="card table-wrap">
<?php if ($items === []): ?>
    <p class="muted" style="margin:0">Пусто — дождитесь PASS от phone-agent.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>IP</th>
            <th>Provider</th>
            <th>ASN</th>
            <th>Операторы</th>
            <th>Статус</th>
            <th>Найден</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td>
                    <code id="ip-<?= (int) $it['id'] ?>"><?= View::e((string) $it['ipv4']) ?></code>
                    <button type="button" class="btn btn-secondary" style="padding:0.15rem 0.4rem;font-size:0.75rem"
                            onclick="navigator.clipboard.writeText('<?= View::e((string) $it['ipv4']) ?>')">copy</button>
                </td>
                <td><?= View::e((string) $it['provider']) ?></td>
                <td class="muted"><?= $it['asn'] ? 'AS' . (int) $it['asn'] : '—' ?><?php if ($it['asn_org']): ?><br><?= View::e((string) $it['asn_org']) ?><?php endif; ?></td>
                <td><?= View::e((string) ($it['operators'] ?? '')) ?></td>
                <td>
                    <span class="badge <?= $it['status'] === 'active' ? 'badge-ok' : 'badge-err' ?>">
                        <?= View::e((string) $it['status']) ?>
                    </span>
                </td>
                <td class="muted"><?= View::e((string) $it['found_at']) ?></td>
                <td>
                    <?php if ($it['status'] === 'active'): ?>
                        <form method="post" action="/inventory/<?= (int) $it['id'] ?>/retire" onsubmit="return confirm('Retire этот IP?')">
                            <?= $csrf ?>
                            <button class="btn btn-danger" type="submit" style="padding:0.25rem 0.45rem;font-size:0.78rem">retire</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
