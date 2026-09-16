<?php
/** @var string $csrf */
/** @var bool $timewebConfigured */
/** @var bool $selectelConfigured */
/** @var bool $bsbordConfigured */
/** @var string|null $defaultRegion */
/** @var string|null $defaultSelectelRegion */
/** @var string|null $defaultBsMode */
/** @var int $maxParallel */
/** @var int $dailyCapacity */
/** @var int $createsPerAccount */
/** @var int $enabledAccountCount */
/** @var array<int, array{used:int,left:int}> $accountUsage */
/** @var list<array<string,mixed>> $timewebAccounts */
/** @var list<array<string,mixed>> $selectelAccounts */
use Wlsearch\Support\View;
$anyProvider = $timewebConfigured || $selectelConfigured;
$bsDefault = $defaultBsMode ?: 'bsbord';
$timewebAccounts = $timewebAccounts ?? [];
$selectelAccounts = $selectelAccounts ?? [];
$dailyCapacity = (int) ($dailyCapacity ?? 0);
$createsPerAccount = (int) ($createsPerAccount ?? 10);
$enabledAccountCount = (int) ($enabledAccountCount ?? 0);
$maxParallel = max(1, (int) ($maxParallel ?? 1));
$accountUsage = $accountUsage ?? [];
$defaultCount = max(1, min(10, $dailyCapacity > 0 ? $dailyCapacity : 1));
?>
<h1>Запуск прогона</h1>
<p class="muted">
    Последовательно: одновременно только <?= (int) $maxParallel ?> VPS (create → check → destroy → следующий).
    Аккаунты: <a href="/accounts">/accounts</a>.
</p>

<?php if (!$anyProvider): ?>
    <div class="flash flash-error">Нет аккаунтов — добавьте в <a href="/accounts">Аккаунты</a> или .env.</div>
<?php endif; ?>

<div class="card">
    <form method="post" action="/runs">
        <?= $csrf ?>

        <label for="provider">Provider</label>
        <select id="provider" name="provider" required
                onchange="(function(v){var tw=document.getElementById('acc-tw'),sel=document.getElementById('acc-sel');tw.style.display=v==='timeweb'?'block':'none';sel.style.display=v==='selectel'?'block':'none';tw.querySelectorAll('input[type=checkbox]').forEach(function(c){c.disabled=v!=='timeweb'||c.dataset.off==='1';});sel.querySelectorAll('input[type=checkbox]').forEach(function(c){c.disabled=v!=='selectel'||c.dataset.off==='1';});})(this.value)">
            <option value="timeweb" <?= $timewebConfigured ? '' : 'disabled' ?>>timeweb <?= $timewebConfigured ? '' : '(не настроен)' ?></option>
            <option value="selectel" <?= $selectelConfigured ? '' : 'disabled' ?>>selectel <?= $selectelConfigured ? '' : '(не настроен)' ?></option>
        </select>

        <div id="acc-tw" style="margin:0.75rem 0">
            <p style="margin:0 0 0.4rem;font-weight:600">Аккаунты Timeweb</p>
            <p class="muted" style="margin:0 0 0.5rem">Галочки = участвуют. Лимит <?= (int) $createsPerAccount ?> create/сутки на аккаунт.</p>
            <?php if ($timewebAccounts === []): ?>
                <p class="muted">Нет аккаунтов — <a href="/accounts">добавить</a></p>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem 1rem">
                    <?php foreach ($timewebAccounts as $a): ?>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer">
                            <input type="checkbox" name="account_id[]" value="<?= (int) $a['id'] ?>"
                                   <?= (int) $a['enabled'] ? 'checked' : '' ?>
                                   <?= (int) $a['enabled'] ? '' : 'disabled' ?>
                                   data-off="<?= (int) $a['enabled'] ? '0' : '1' ?>">
                            #<?= (int) $a['id'] ?> <?= View::e((string) $a['name']) ?>
                            <?php if (!(int) $a['enabled']): ?><span class="muted">(выкл)</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="acc-sel" style="display:none;margin:0.75rem 0">
            <p style="margin:0 0 0.4rem;font-weight:600">Аккаунты Selectel</p>
            <?php if ($selectelAccounts === []): ?>
                <p class="muted">Нет аккаунтов — <a href="/accounts">добавить</a></p>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem 1rem">
                    <?php foreach ($selectelAccounts as $a): ?>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer">
                            <input type="checkbox" name="account_id[]" value="<?= (int) $a['id'] ?>"
                                   disabled
                                   data-off="<?= (int) $a['enabled'] ? '0' : '1' ?>"
                                   <?= (int) $a['enabled'] ? 'checked' : '' ?>>
                            #<?= (int) $a['id'] ?> <?= View::e((string) $a['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <label for="region">Region / AZ (опц.)</label>
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
                both — bsbord или agent
            </option>
        </select>

        <label for="count">Сколько IP перебрать (очередь)</label>
        <input id="count" name="count" type="number" min="1" max="100"
               value="<?= (int) $defaultCount ?>" required>
        <p class="muted">
            Сегодня осталось ≈ <strong><?= (int) $dailyCapacity ?></strong>
            (лимит <?= (int) $createsPerAccount ?> IP/сутки на аккаунт; в лимите только выданные IPv4, попытки без IP не считаются).
            Параллельно живых VM: <?= (int) $maxParallel ?>.
        </p>
        <?php if (!empty($accountUsage)): ?>
            <p class="muted" style="margin-top:0.35rem">
                <?php foreach ($accountUsage as $aid => $u): ?>
                    #<?= (int) $aid ?>: выдано <?= (int) $u['used'] ?>/<?= (int) $createsPerAccount ?>
                    (осталось <?= (int) $u['left'] ?>)<?= $aid !== array_key_last($accountUsage) ? '; ' : '' ?>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if ($dailyCapacity <= 0): ?>
            <div class="flash flash-error" style="margin-top:0.75rem">
                Лимит выданных IP на сегодня исчерпан для включённых аккаунтов (или баланс &lt; ~880 ₽).
                Включите другой аккаунт в <a href="/accounts">Аккаунты</a>, пополните баланс, либо дождитесь завтра.
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
