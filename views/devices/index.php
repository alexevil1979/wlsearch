<?php
/** @var list<array<string,mixed>> $devices */
/** @var string $csrf */
/** @var string|null $newToken */
use Wlsearch\Support\View;
?>
<h1>Devices / agents</h1>
<p class="muted">Phone-агенты Termux. Token показывается один раз при создании.</p>

<?php if (!empty($newToken)): ?>
    <div class="flash flash-ok">
        <strong>Новый token (сохраните):</strong><br>
        <code style="word-break:break-all"><?= View::e($newToken) ?></code>
        <button type="button" class="btn btn-secondary" style="margin-left:0.5rem;padding:0.25rem 0.5rem;font-size:0.8rem"
                onclick="navigator.clipboard.writeText('<?= View::e($newToken) ?>')">copy</button>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Создать агента</h2>
    <form method="post" action="/devices">
        <?= $csrf ?>
        <label for="name">Имя</label>
        <input id="name" name="name" type="text" required placeholder="phone-mts-1">

        <label for="operator">Оператор</label>
        <select id="operator" name="operator">
            <option value="mts">mts</option>
            <option value="beeline">beeline</option>
            <option value="megafon">megafon</option>
            <option value="other">other</option>
        </select>

        <div style="margin-top:1rem">
            <button class="btn" type="submit">Создать token</button>
        </div>
    </form>
</div>

<div class="card table-wrap">
    <h2>Список</h2>
    <?php if ($devices === []): ?>
        <p class="muted">Нет устройств.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Имя</th>
                <th>Оператор</th>
                <th>Prefix</th>
                <th>Last seen</th>
                <th>Статус</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $d): ?>
                <?php
                $online = !empty($d['last_seen_at']) && (time() - strtotime((string) $d['last_seen_at'])) < 120;
                $revoked = !empty($d['revoked_at']);
                ?>
                <tr>
                    <td>#<?= (int) $d['id'] ?></td>
                    <td><?= View::e((string) $d['name']) ?></td>
                    <td><?= View::e((string) $d['operator']) ?></td>
                    <td><code><?= View::e((string) $d['token_prefix']) ?>…</code></td>
                    <td class="muted"><?= View::e((string) ($d['last_seen_at'] ?? '—')) ?></td>
                    <td>
                        <?php if ($revoked): ?>
                            <span class="badge badge-err">revoked</span>
                        <?php elseif ($online): ?>
                            <span class="badge badge-ok">online</span>
                        <?php else: ?>
                            <span class="badge">offline</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$revoked): ?>
                            <form method="post" action="/devices/<?= (int) $d['id'] ?>/revoke" onsubmit="return confirm('Revoke token?')">
                                <?= $csrf ?>
                        <button class="btn btn-danger btn-sm" type="submit">revoke</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Termux</h2>
    <p class="muted">Скрипт: <code>agents/termux/wlsearch_agent.sh</code> — см. docs/PHONE_AGENT.md</p>
</div>
