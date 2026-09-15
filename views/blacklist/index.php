<?php
/** @var list<array<string,mixed>> $items */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<h1>Blacklist ASN / prefix</h1>
<p class="muted">Заблокированные ASN или CIDR — run уйдёт в ERROR и destroy (если не keep).</p>

<div class="card">
    <h2>Добавить</h2>
    <form method="post" action="/blacklist">
        <?= $csrf ?>
        <label for="kind">Тип</label>
        <select id="kind" name="kind">
            <option value="asn">asn</option>
            <option value="prefix">prefix (CIDR)</option>
        </select>
        <label for="value">Значение</label>
        <input id="value" name="value" type="text" required placeholder="49505 или 185.12.0.0/16">
        <label for="reason">Причина</label>
        <input id="reason" name="reason" type="text" placeholder="опционально">
        <div style="margin-top:1rem">
            <button class="btn" type="submit">Добавить</button>
        </div>
    </form>
</div>

<div class="card table-wrap">
    <?php if ($items === []): ?>
        <p class="muted">Список пуст.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Kind</th><th>Value</th><th>Reason</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= View::e((string) $it['kind']) ?></td>
                    <td><code><?= View::e((string) $it['value']) ?></code></td>
                    <td class="muted"><?= View::e((string) ($it['reason'] ?? '')) ?></td>
                    <td class="muted"><?= View::e((string) $it['created_at']) ?></td>
                    <td>
                        <form method="post" action="/blacklist/<?= (int) $it['id'] ?>/delete" onsubmit="return confirm('Удалить?')">
                            <?= $csrf ?>
                            <button class="btn btn-danger btn-sm" type="submit">delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
