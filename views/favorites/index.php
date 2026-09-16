<?php
/** @var list<array<string,mixed>> $items */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<h1>Избранные подсети</h1>
<p class="muted">
    Если публичный IP VPS попадает в CIDR из списка — очередь останавливается полностью
    (ORDERING→SKIPPED, живые→KEEP без destroy), в Telegram уходит сообщение
    «избранная подсеть попалась».
</p>

<div class="card">
    <h2>Добавить</h2>
    <form method="post" action="/favorites">
        <?= $csrf ?>
        <label for="cidr">CIDR / IP</label>
        <input id="cidr" name="cidr" type="text" required placeholder="51.250.0.0/16 или 51.250.66.248">
        <label for="note">Заметка</label>
        <input id="note" name="note" type="text" placeholder="опционально">
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
            <tr><th>CIDR</th><th>Заметка</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><code><?= View::e((string) $it['cidr']) ?></code></td>
                    <td class="muted"><?= View::e((string) ($it['note'] ?? '')) ?></td>
                    <td class="muted"><?= View::e((string) $it['created_at']) ?></td>
                    <td>
                        <form method="post" action="/favorites/<?= (int) $it['id'] ?>/delete" onsubmit="return confirm('Удалить?')">
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
