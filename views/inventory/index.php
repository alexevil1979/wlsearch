<?php
/** @var list<array<string,mixed>> $items */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<div class="page-head">
    <div>
        <h1>Inventory PASS</h1>
        <p class="muted">Белые IP: control_ok ∧ bs_ok ∧ cellular ∧ маркер WL_PROBE_OK.</p>
    </div>
</div>

<div class="card table-wrap">
<?php if ($items === []): ?>
    <p class="muted" style="margin:0">Пусто — дождитесь PASS.</p>
<?php else: ?>
    <table class="data">
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
                <td class="cell-narrow">
                    <code><?= View::e((string) $it['ipv4']) ?></code>
                    <button type="button" class="btn btn-secondary btn-sm"
                            onclick="navigator.clipboard.writeText('<?= View::e((string) $it['ipv4']) ?>')">copy</button>
                </td>
                <td class="cell-narrow"><?= View::e((string) $it['provider']) ?></td>
                <td class="muted cell-narrow"><?= $it['asn'] ? 'AS' . (int) $it['asn'] : '—' ?></td>
                <td class="cell-clip" title="<?= View::e((string) ($it['operators'] ?? '')) ?>"><?= View::e((string) ($it['operators'] ?? '')) ?></td>
                <td class="cell-narrow">
                    <span class="badge <?= $it['status'] === 'active' ? 'badge-ok' : 'badge-err' ?>">
                        <?= View::e((string) $it['status']) ?>
                    </span>
                </td>
                <td class="muted cell-narrow"><?= View::e((string) $it['found_at']) ?></td>
                <td class="cell-actions">
                    <?php if ($it['status'] === 'active'): ?>
                        <form method="post" action="/inventory/<?= (int) $it['id'] ?>/retire" onsubmit="return confirm('Retire этот IP?')">
                            <?= $csrf ?>
                            <button class="btn btn-danger btn-sm" type="submit">retire</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
