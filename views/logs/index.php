<?php
/** @var list<array<string,mixed>> $rows */
use Wlsearch\Support\View;
?>
<h1>Логи / audit</h1>
<p class="muted">Кто запускал run, destroy, смену лимитов, revoke агентов.</p>

<div class="card table-wrap">
<?php if ($rows === []): ?>
    <p class="muted">Пока пусто.</p>
<?php else: ?>
    <table>
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
            <tr>
                <td class="muted"><?= View::e((string) $r['created_at']) ?></td>
                <td><?= View::e((string) $r['actor']) ?></td>
                <td><code><?= View::e((string) $r['action']) ?></code></td>
                <td class="muted">
                    <?= View::e((string) ($r['entity_type'] ?? '')) ?>
                    <?= View::e((string) ($r['entity_id'] ?? '')) ?>
                </td>
                <td class="muted"><?= View::e((string) ($r['ip'] ?? '')) ?></td>
                <td class="muted" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= View::e((string) ($r['details_json'] ?? '')) ?>">
                    <?= View::e(mb_substr((string) ($r['details_json'] ?? ''), 0, 80)) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
