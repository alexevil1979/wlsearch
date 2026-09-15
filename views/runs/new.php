<?php
/** @var string $csrf */
/** @var bool $timewebConfigured */
/** @var bool $selectelConfigured */
/** @var bool $bsbordConfigured */
/** @var string|null $defaultRegion */
/** @var string|null $defaultSelectelRegion */
/** @var string|null $defaultBsMode */
/** @var int $maxParallel */
/** @var int $maxCreates */
use Wlsearch\Support\View;
$anyProvider = $timewebConfigured || $selectelConfigured;
$bsDefault = $defaultBsMode ?: 'bsbord';
?>
<h1>Запуск прогона</h1>
<p class="muted">create → control → BS-проверка (по умолчанию bsbord.com).</p>

<?php if (!$anyProvider): ?>
    <div class="flash flash-error">Ни один провайдер не настроен в .env.</div>
<?php endif; ?>

<div class="card">
    <form method="post" action="/runs">
        <?= $csrf ?>

        <label for="provider">Provider</label>
        <select id="provider" name="provider" required>
            <option value="timeweb" <?= $timewebConfigured ? '' : 'disabled' ?>>timeweb <?= $timewebConfigured ? '' : '(не настроен)' ?></option>
            <option value="selectel" <?= $selectelConfigured ? '' : 'disabled' ?>>selectel <?= $selectelConfigured ? '' : '(не настроен)' ?></option>
        </select>

        <label for="region">Region / AZ</label>
        <input id="region" name="region" type="text"
               value="<?= View::e((string) ($timewebConfigured ? $defaultRegion : $defaultSelectelRegion)) ?>"
               placeholder="spb-3 или ru-9a">

        <label for="bs_mode">BS-проверка</label>
        <select id="bs_mode" name="bs_mode" required>
            <option value="bsbord" <?= $bsDefault === 'bsbord' ? 'selected' : '' ?> <?= $bsbordConfigured ? '' : 'disabled' ?>>
                bsbord.com API <?= $bsbordConfigured ? '(основной)' : '(нужен BSBORD_API_TOKEN)' ?>
            </option>
            <option value="agent" <?= $bsDefault === 'agent' ? 'selected' : '' ?>>Android SIM (Termux agent)</option>
            <option value="both" <?= $bsDefault === 'both' ? 'selected' : '' ?> <?= $bsbordConfigured ? '' : 'disabled' ?>>
                both — bsbord или agent (PASS при любом)
            </option>
        </select>
        <p class="muted">bsbord: мобильные каналы с dpi=on через https://bsbord.com/v1/probe</p>

        <label for="count">Count</label>
        <input id="count" name="count" type="number" min="1" max="20" value="1" required>
        <p class="muted">Лимиты: parallel ≤ <?= (int) $maxParallel ?>, creates/day ≤ <?= (int) $maxCreates ?></p>

        <label>
            <input type="checkbox" name="keep_on_fail" value="1">
            keep_on_fail — не удалять VPS при FAIL
        </label>

        <label for="comment">Комментарий</label>
        <input id="comment" name="comment" type="text" maxlength="255" placeholder="опционально">

        <div style="margin-top:1.2rem">
            <button class="btn" type="submit" <?= $anyProvider ? '' : 'disabled' ?>>Создать run</button>
            <a class="btn btn-secondary" href="/runs">К списку</a>
        </div>
    </form>
</div>
