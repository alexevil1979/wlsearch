<?php
/** @var string $csrf */
/** @var bool $timewebConfigured */
/** @var string|null $defaultRegion */
/** @var int $maxParallel */
/** @var int $maxCreates */
use Wlsearch\Support\View;
?>
<h1>Запуск прогона</h1>
<p class="muted">Создаёт run(s) в состоянии ORDERING. Worker (`php bin/wlsearch worker`) создаст VPS и сделает control-check.</p>

<?php if (!$timewebConfigured): ?>
    <div class="flash flash-error">TIMEWEB_API_TOKEN не задан в .env — создание не сработает.</div>
<?php endif; ?>

<div class="card">
    <form method="post" action="/runs">
        <?= $csrf ?>

        <label for="provider">Provider</label>
        <select id="provider" name="provider" required>
            <option value="timeweb" selected>timeweb</option>
            <option value="selectel" disabled>selectel (Phase 3)</option>
        </select>

        <label for="region">Region / availability_zone</label>
        <input id="region" name="region" type="text" value="<?= View::e((string) $defaultRegion) ?>" placeholder="spb-3">
        <p class="muted">Timeweb: spb-1, spb-3, spb-4, msk-1, nsk-1, …</p>

        <label for="count">Count</label>
        <input id="count" name="count" type="number" min="1" max="20" value="1" required>
        <p class="muted">Лимиты: parallel ≤ <?= (int) $maxParallel ?>, creates/day ≤ <?= (int) $maxCreates ?></p>

        <label>
            <input type="checkbox" name="keep_on_fail" value="1">
            keep_on_fail — не удалять VPS при FAIL_CONTROL / FAIL_BS
        </label>

        <label for="comment">Комментарий</label>
        <input id="comment" name="comment" type="text" maxlength="255" placeholder="опционально">

        <div style="margin-top:1.2rem">
            <button class="btn" type="submit" <?= $timewebConfigured ? '' : 'disabled' ?>>Создать run</button>
            <a class="btn btn-secondary" href="/runs">К списку</a>
        </div>
    </form>
</div>
