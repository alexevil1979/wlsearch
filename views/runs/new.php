<?php
/** @var string $csrf */
/** @var bool $timewebConfigured */
/** @var bool $selectelConfigured */
/** @var bool $yandexConfigured */
/** @var bool $bsbordConfigured */
/** @var string|null $defaultRegion */
/** @var string|null $defaultSelectelRegion */
/** @var string|null $defaultYandexRegion */
/** @var string|null $defaultBsMode */
/** @var string $preferredProvider */
/** @var int $maxParallel */
/** @var int $dailyCapacity */
/** @var int $createsPerAccount */
/** @var int $enabledAccountCount */
/** @var array<int, array{used:int,left:int}> $accountUsage */
/** @var list<array<string,mixed>> $timewebAccounts */
/** @var list<array<string,mixed>> $selectelAccounts */
/** @var list<array<string,mixed>> $yandexAccounts */
use Wlsearch\Support\View;
$yandexConfigured = $yandexConfigured ?? false;
$anyProvider = $timewebConfigured || $selectelConfigured || $yandexConfigured;
$bsDefault = $defaultBsMode ?: 'bsbord';
$timewebAccounts = $timewebAccounts ?? [];
$selectelAccounts = $selectelAccounts ?? [];
$yandexAccounts = $yandexAccounts ?? [];
$preferredProvider = $preferredProvider ?? ($yandexConfigured ? 'yandex' : ($timewebConfigured ? 'timeweb' : 'selectel'));
$dailyCapacity = (int) ($dailyCapacity ?? 0);
$createsPerAccount = (int) ($createsPerAccount ?? 0);
$enabledAccountCount = (int) ($enabledAccountCount ?? 0);
$maxParallel = max(1, (int) ($maxParallel ?? 1));
$accountUsage = $accountUsage ?? [];
$defaultCount = max(1, min(10, $dailyCapacity > 0 ? min($dailyCapacity, 10) : 1));
$limitHint = $createsPerAccount > 0
    ? ((int) $createsPerAccount . ' IP/сутки на аккаунт')
    : 'без лимита на аккаунт';
$defaultRegionValue = match ($preferredProvider) {
    'yandex' => (string) ($defaultYandexRegion ?? 'ru-central1-a'),
    'selectel' => (string) ($defaultSelectelRegion ?? 'ru-9a'),
    default => (string) ($defaultRegion ?? 'spb-3'),
};
$yandexZones = ['ru-central1-a', 'ru-central1-b', 'ru-central1-d', 'ru-central1-e'];
foreach ($yandexAccounts as $a) {
    $cfg = json_decode((string) ($a['config_json'] ?? ''), true);
    if (!is_array($cfg)) {
        continue;
    }
    $z = trim((string) ($cfg['YANDEX_ZONE_ID'] ?? ''));
    if ($z !== '' && !in_array($z, $yandexZones, true)) {
        $yandexZones[] = $z;
    }
}
$timewebZones = ['spb-3', 'spb-1', 'spb-2', 'msk-1', 'nsk-1'];
$twDef = (string) ($defaultRegion ?? 'spb-3');
if ($twDef !== '' && !in_array($twDef, $timewebZones, true)) {
    $timewebZones[] = $twDef;
}
$selectelZones = ['ru-9a', 'ru-1a', 'ru-2a', 'ru-3a', 'ru-7a'];
$selDef = (string) ($defaultSelectelRegion ?? 'ru-9a');
if ($selDef !== '' && !in_array($selDef, $selectelZones, true)) {
    $selectelZones[] = $selDef;
}
$regionsByProvider = [
    'yandex' => $yandexZones,
    'timeweb' => $timewebZones,
    'selectel' => $selectelZones,
];
?>
<h1>Запуск прогона</h1>
<p class="muted">
    Последовательно: одновременно только <?= (int) $maxParallel ?> VPS (create → check → destroy → следующий).
    Аккаунты: <a href="/accounts">/accounts</a>. Лимит: <a href="/settings">настройки</a>.
</p>

<?php if (!$anyProvider): ?>
    <div class="flash flash-error">Нет аккаунтов — добавьте в <a href="/accounts">Аккаунты</a> или .env.</div>
<?php endif; ?>

<div class="card">
    <form method="post" action="/runs">
        <?= $csrf ?>

        <label for="provider">Provider</label>
        <select id="provider" name="provider" required>
            <option value="yandex" <?= $preferredProvider === 'yandex' ? 'selected' : '' ?> <?= $yandexConfigured ? '' : 'disabled' ?>>yandex <?= $yandexConfigured ? '' : '(не настроен)' ?></option>
            <option value="timeweb" <?= $preferredProvider === 'timeweb' ? 'selected' : '' ?> <?= $timewebConfigured ? '' : 'disabled' ?>>timeweb <?= $timewebConfigured ? '' : '(не настроен)' ?></option>
            <option value="selectel" <?= $preferredProvider === 'selectel' ? 'selected' : '' ?> <?= $selectelConfigured ? '' : 'disabled' ?>>selectel <?= $selectelConfigured ? '' : '(не настроен)' ?></option>
        </select>

        <div id="acc-tw" style="margin:0.75rem 0;<?= $preferredProvider === 'timeweb' ? '' : 'display:none' ?>">
            <p style="margin:0 0 0.4rem;font-weight:600">Аккаунты Timeweb</p>
            <p class="muted" style="margin:0 0 0.5rem">Галочки = участвуют. <?= View::e($limitHint) ?>.</p>
            <?php if ($timewebAccounts === []): ?>
                <p class="muted">Нет аккаунтов — <a href="/accounts">добавить</a></p>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem 1rem">
                    <?php foreach ($timewebAccounts as $a): ?>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer">
                            <input type="checkbox" name="account_id[]" value="<?= (int) $a['id'] ?>"
                                   <?= $preferredProvider === 'timeweb' && (int) $a['enabled'] ? 'checked' : '' ?>
                                   <?= $preferredProvider === 'timeweb' && (int) $a['enabled'] ? '' : 'disabled' ?>
                                   data-off="<?= (int) $a['enabled'] ? '0' : '1' ?>">
                            #<?= (int) $a['id'] ?> <?= View::e((string) $a['name']) ?>
                            <?php if (!(int) $a['enabled']): ?><span class="muted">(выкл)</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="acc-sel" style="margin:0.75rem 0;<?= $preferredProvider === 'selectel' ? '' : 'display:none' ?>">
            <p style="margin:0 0 0.4rem;font-weight:600">Аккаунты Selectel</p>
            <?php if ($selectelAccounts === []): ?>
                <p class="muted">Нет аккаунтов — <a href="/accounts">добавить</a></p>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem 1rem">
                    <?php foreach ($selectelAccounts as $a): ?>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer">
                            <input type="checkbox" name="account_id[]" value="<?= (int) $a['id'] ?>"
                                   <?= $preferredProvider === 'selectel' && (int) $a['enabled'] ? 'checked' : '' ?>
                                   <?= $preferredProvider === 'selectel' && (int) $a['enabled'] ? '' : 'disabled' ?>
                                   data-off="<?= (int) $a['enabled'] ? '0' : '1' ?>">
                            #<?= (int) $a['id'] ?> <?= View::e((string) $a['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="acc-yc" style="margin:0.75rem 0;<?= $preferredProvider === 'yandex' ? '' : 'display:none' ?>">
            <p style="margin:0 0 0.4rem;font-weight:600">Аккаунты Yandex Cloud</p>
            <p class="muted" style="margin:0 0 0.5rem">Галочки = участвуют. <?= View::e($limitHint) ?>.</p>
            <?php if ($yandexAccounts === []): ?>
                <p class="muted">Нет аккаунтов — <a href="/accounts">добавить</a></p>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem 1rem">
                    <?php foreach ($yandexAccounts as $a): ?>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer">
                            <input type="checkbox" name="account_id[]" value="<?= (int) $a['id'] ?>"
                                   <?= $preferredProvider === 'yandex' && (int) $a['enabled'] ? 'checked' : '' ?>
                                   <?= $preferredProvider === 'yandex' && (int) $a['enabled'] ? '' : 'disabled' ?>
                                   data-off="<?= (int) $a['enabled'] ? '0' : '1' ?>">
                            #<?= (int) $a['id'] ?> <?= View::e((string) $a['name']) ?>
                            <?php if (!(int) $a['enabled']): ?><span class="muted">(выкл)</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <label for="region">Zone / Region</label>
        <select id="region" name="region" required>
            <?php
            $opts = $regionsByProvider[$preferredProvider] ?? $yandexZones;
            foreach ($opts as $z):
            ?>
                <option value="<?= View::e($z) ?>" <?= $defaultRegionValue === $z ? 'selected' : '' ?>><?= View::e($z) ?></option>
            <?php endforeach; ?>
            <?php if ($defaultRegionValue !== '' && !in_array($defaultRegionValue, $opts, true)): ?>
                <option value="<?= View::e($defaultRegionValue) ?>" selected><?= View::e($defaultRegionValue) ?></option>
            <?php endif; ?>
        </select>
        <p class="muted" style="margin:0.25rem 0 0;font-size:0.8rem">
            Для Yandex зона должна совпадать с subnet аккаунта в <a href="/accounts">Аккаунты</a>.
        </p>

        <label for="bs_mode">BS-проверка</label>
        <select id="bs_mode" name="bs_mode" required>
            <option value="bsbord" <?= $bsDefault === 'bsbord' ? 'selected' : '' ?> <?= $bsbordConfigured ? '' : 'disabled' ?>>
                bsbord.com API <?= $bsbordConfigured ? '(основной)' : '(нужен BSBORD_API_TOKEN)' ?>
            </option>
            <option value="agent" <?= $bsDefault === 'agent' ? 'selected' : '' ?>>Android SIM (Termux agent)</option>
            <option value="both" <?= $bsDefault === 'both' ? 'selected' : '' ?> <?= $bsbordConfigured ? '' : 'disabled' ?>>
                both — bsbord или agent
            </option>
        </select>

        <label for="count">Сколько IP перебрать (очередь)</label>
        <input id="count" name="count" type="number" min="1" max="100"
               value="<?= (int) $defaultCount ?>" required>
        <p class="muted">
            Сегодня осталось ≈ <strong><?= (int) $dailyCapacity ?></strong>
            (<?= View::e($limitHint) ?>; в лимите только выданные IPv4).
            Параллельно живых VM: <?= (int) $maxParallel ?>.
        </p>
        <?php if (!empty($accountUsage) && $createsPerAccount > 0): ?>
            <p class="muted" style="margin-top:0.35rem">
                <?php foreach ($accountUsage as $aid => $u): ?>
                    #<?= (int) $aid ?>: выдано <?= (int) $u['used'] ?>/<?= (int) $createsPerAccount ?>
                    (осталось <?= (int) $u['left'] ?>)<?= $aid !== array_key_last($accountUsage) ? '; ' : '' ?>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if ($createsPerAccount > 0 && $dailyCapacity <= 0): ?>
            <div class="flash flash-error" style="margin-top:0.75rem">
                Лимит выданных IP на сегодня исчерпан для включённых аккаунтов.
                Включите другой аккаунт в <a href="/accounts">Аккаунты</a> либо дождитесь завтра / смените лимит в <a href="/settings">настройках</a>.
            </div>
        <?php endif; ?>

        <label style="display:inline-flex;align-items:center;gap:0.4rem;margin-top:0.85rem">
            <input type="checkbox" name="stop_on_pass" value="1" checked>
            Останавливать очередь, если найден PASS по BS
        </label>

        <label style="display:inline-flex;align-items:center;gap:0.4rem;margin-top:0.5rem">
            <input type="checkbox" name="keep_on_fail" value="1">
            keep_on_fail — не удалять VPS при FAIL
        </label>

        <label for="comment">Комментарий</label>
        <input id="comment" name="comment" type="text" maxlength="255" placeholder="опционально">

        <div style="margin-top:1.2rem;display:flex;flex-wrap:wrap;gap:0.6rem;align-items:center">
            <button class="btn" type="submit" <?= $anyProvider ? '' : 'disabled' ?>>Создать очередь</button>
            <a class="btn btn-secondary" href="/runs">К списку</a>
            <a class="btn btn-danger" href="/runs">Остановить очередь (на Runs)</a>
        </div>
    </form>
</div>
