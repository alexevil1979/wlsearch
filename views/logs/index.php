<?php
/** @var list<array<string,mixed>> $rows */
use Wlsearch\Support\View;
?>
<div class="page-head">
    <div>
        <h1>Логи / audit</h1>
        <p class="muted">Кто запускал run, destroy, смену лимитов, revoke агентов.</p>
    </div>
</div>

<div class="card table-wrap">
<?php if ($rows === []): ?>
    <p class="muted">Пока пусто.</p>
<?php else: ?>
    <table class="data">
        <thead>
        <tr>
            <th>Время</th>
            <th>Actor</th>
            <th>Action</th>
            <th>Entity</th>
            <th>IP</th>
            <th>Details</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php $details = (string) ($r['details_json'] ?? ''); ?>
            <tr>
                <td class="muted cell-narrow"><?= View::e((string) $r['created_at']) ?></td>
                <td class="cell-narrow"><?= View::e((string) $r['actor']) ?></td>
                <td class="cell-narrow"><code><?= View::e((string) $r['action']) ?></code></td>
                <td class="muted cell-narrow">
                    <?= View::e((string) ($r['entity_type'] ?? '')) ?>
                    <?= View::e((string) ($r['entity_id'] ?? '')) ?>
                </td>
                <td class="muted cell-narrow"><?= View::e((string) ($r['ip'] ?? '')) ?></td>
                <td class="cell-error" title="<?= View::e($details) ?>"><?= View::e($details) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
