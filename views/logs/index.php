<?php
/** @var list<array<string,mixed>> $rows */
/** @var list<string> $channels */
/** @var string $channel */
/** @var string $filter */
/** @var int $lines */
/** @var string $rawLog */
use Wlsearch\Support\View;
$channels = $channels ?? [];
$channel = $channel ?? 'probe';
$filter = $filter ?? '';
$lines = (int) ($lines ?? 300);
$rawLog = $rawLog ?? '';
?>
<div class="page-head">
    <div>
        <h1>Логи</h1>
        <p class="muted">Сырые файловые логи (probe/worker/yandex…) и audit действий в админке.</p>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0">Сырые логи</h2>
    <form method="get" action="/logs" class="form-grid" style="max-width:100%;margin-bottom:0.75rem">
        <div>
            <label for="file">Канал</label>
            <select id="file" name="file">
                <?php foreach ($channels as $ch): ?>
                    <option value="<?= View::e($ch) ?>" <?= $ch === $channel ? 'selected' : '' ?>><?= View::e($ch) ?>.log</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="q">Фильтр (подстрока, напр. run_id / IP)</label>
            <input id="q" name="q" type="text" value="<?= View::e($filter) ?>" placeholder='run_id":47 или 89.169.'>
        </div>
        <div>
            <label for="lines">Строк</label>
            <input id="lines" name="lines" type="number" min="50" max="1000" value="<?= (int) $lines ?>">
        </div>
        <div style="display:flex;align-items:flex-end;gap:0.5rem">
            <button class="btn" type="submit">Показать</button>
            <a class="btn btn-secondary" href="/logs?file=<?= View::e(rawurlencode($channel)) ?>&lines=<?= (int) $lines ?>">Обновить</a>
        </div>
    </form>
    <p class="muted" style="margin:0 0 0.4rem">
        Файл: <code>storage/logs/<?= View::e($channel) ?>.log</code>
        · автообновление страницы каждые 5с, пока открыт этот блок
    </p>
    <pre id="raw-log" style="white-space:pre-wrap;word-break:break-word;font-size:0.72rem;max-height:28rem;overflow:auto;margin:0;padding:0.75rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius)"><?= View::e($rawLog !== '' ? $rawLog : '(пусто — worker ещё не писал в этот канал)') ?></pre>
</div>

<div class="page-head" style="margin-top:1.2rem">
    <div>
        <h2 style="margin:0">Audit</h2>
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

<script>
(function () {
    setTimeout(function () {
        if (!document.hidden) window.location.reload();
    }, 5000);
})();
</script>
