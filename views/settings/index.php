<?php
/** @var array<string,string|null> $values */
/** @var string $csrf */
/** @var array<string, array{title:string, units:list<array}> $bsByRegion */
/** @var list<string> $bsSelected */
/** @var string|null $bsError */
/** @var bool $bsConfigured */
use Wlsearch\Support\View;
$bsByRegion = $bsByRegion ?? [];
$bsSelected = $bsSelected ?? [];
?>
<h1>Лимиты и настройки</h1>
<p class="muted">Значения в <code>settings</code> перекрывают `.env`.</p>

<div class="card">
    <form method="post" action="/settings" id="settings-form">
        <?= $csrf ?>
        <input type="hidden" name="bs_ops_cleared" value="1">

        <h2>Общие</h2>
        <?php
        $labels = [
            'MAX_PARALLEL_VMS' => 'Макс. параллельных VM',
            'MAX_CREATES_PER_DAY' => 'Макс. create в сутки',
            'MAX_DAILY_SPEND_RUB' => 'Макс. оценка spend ₽/сутки',
            'TELEGRAM_CHAT_ID' => 'Telegram chat id',
            'BS_TASK_TTL_SEC' => 'TTL BS-задачи (сек)',
            'PROVISION_TIMEOUT_SEC' => 'Timeout provision (сек)',
            'BOOTSTRAP_TIMEOUT_SEC' => 'Timeout bootstrap (сек)',
            'CONTROL_CHECK_TIMEOUT_SEC' => 'Timeout control (сек)',
            'BS_MODE_DEFAULT' => 'Дефолт BS-режима (agent|bsbord|both)',
            'BSBORD_MIN_PASS' => 'Мин. число операторов БС с PASS',
        ];
        foreach ($labels as $key => $label):
        ?>
            <label for="<?= View::e($key) ?>"><?= View::e($label) ?></label>
            <input id="<?= View::e($key) ?>" name="<?= View::e($key) ?>" type="text"
                   value="<?= View::e((string) ($values[$key] ?? '')) ?>">
        <?php endforeach; ?>

        <h2 style="margin-top:1.4rem">Timeweb VPS</h2>
        <p class="muted" style="margin:0 0 0.75rem">
            Создание через <code>configuration</code> (не preset): zone / os / configurator / cpu / ram / disk.
            Токен API остаётся в <code>.env</code> (<code>TIMEWEB_API_TOKEN</code>).
            Биллинг Timeweb Cloud <strong>всегда почасовой</strong> — в API нет переключателя «месяц/час».
            Метка «Не оплачен» = на балансе мало запаса ≈ на 30 дней этого тарифа (configurator обычно дороже preset).
            Если заполнен Preset id и включён force preset — используется preset.
        </p>
        <?php
        $twLabels = [
            'TIMEWEB_AVAILABILITY_ZONE' => 'Zone (availability_zone)',
            'TIMEWEB_OS_ID' => 'OS id',
            'TIMEWEB_CONFIGURATOR_ID' => 'Configurator id',
            'TIMEWEB_CPU' => 'CPU (ядра)',
            'TIMEWEB_GPU' => 'GPU',
            'TIMEWEB_RAM_GB' => 'RAM (ГБ)',
            'TIMEWEB_DISK_GB' => 'Диск (ГБ)',
            'TIMEWEB_BANDWIDTH' => 'Bandwidth (Мбит/с)',
            'TIMEWEB_PRESET_ID' => 'Preset id (игнор., если есть configurator)',
            'TIMEWEB_PROJECT_ID' => 'Project id (опц.)',
            'TIMEWEB_PRESET_COST_RUB' => 'Оценка стоимости ₽ (лимиты)',
            'TIMEWEB_ENSURE_IPV4' => 'Заказывать IPv4 (1/0)',
            'TIMEWEB_FLOATING_IP_ID' => 'Pinned floating IP id/адрес',
            'TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY' => 'Удалять IP при destroy (1/0)',
        ];
        foreach ($twLabels as $key => $label):
        ?>
            <label for="<?= View::e($key) ?>"><?= View::e($label) ?></label>
            <input id="<?= View::e($key) ?>" name="<?= View::e($key) ?>" type="text"
                   value="<?= View::e((string) ($values[$key] ?? '')) ?>">
        <?php endforeach; ?>

        <h2 style="margin-top:1.4rem">bsbord API</h2>
        <label for="BSBORD_API_TOKEN">Токен (bsk_live_…)</label>
        <input id="BSBORD_API_TOKEN" name="BSBORD_API_TOKEN" type="text"
               value="<?= View::e((string) ($values['BSBORD_API_TOKEN'] ?? '')) ?>"
               autocomplete="off">

        <p class="muted" style="margin-top:0.8rem">
            Проверка как в bsbord: только каналы <span class="badge badge-ok">БС</span> (dpi=on).
            Каналы <span class="badge badge-warn">без БС</span> не используются.
        </p>

        <?php if (!$bsConfigured): ?>
            <div class="flash flash-error">Задайте токен и сохраните — появится список операторов БС.</div>
        <?php elseif ($bsError): ?>
            <div class="flash flash-error">Не удалось загрузить операторов: <?= View::e($bsError) ?></div>
        <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin:0.75rem 0">
                <span class="badge badge-ok">БС — <?= count($bsByRegion) ? array_sum(array_map(static fn ($r) => count($r['units']), $bsByRegion)) : 0 ?></span>
                <span class="muted">выбрано <strong id="bs-count"><?= count($bsSelected) ?></strong></span>
            </div>

            <div style="margin-bottom:0.75rem">
                <button type="submit" formaction="/settings/bsbord-cfo" class="btn btn-secondary" style="font-size:0.85rem">
                    Выбрать МегаФон + МТС + Билайн ЦФО (БС)
                </button>
            </div>

            <?php foreach ($bsByRegion as $rc => $group): ?>
                <div style="margin:0.85rem 0 0.35rem;font-weight:600">
                    <?= View::e($group['title']) ?>
                    <span class="muted" style="font-weight:400">(<?= View::e((string) $rc) ?>)</span>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:0.45rem 0.9rem">
                    <?php foreach ($group['units'] as $u): ?>
                        <?php $checked = in_array($u['op_key'], $bsSelected, true); ?>
                        <label style="display:inline-flex;align-items:center;gap:0.35rem;color:var(--text);cursor:pointer">
                            <input type="checkbox" name="bs_op[]" value="<?= View::e($u['op_key']) ?>"
                                   <?= $checked ? 'checked' : '' ?>
                                   onchange="document.getElementById('bs-count').textContent=document.querySelectorAll('input[name=\'bs_op[]\']:checked').length">
                            <span class="badge badge-ok">БС</span>
                            <?= View::e($u['name'] !== '' ? $u['name'] : $u['operator']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($bsByRegion === []): ?>
                <p class="muted">Список пуст — проверьте тариф/токен bsbord.</p>
            <?php endif; ?>
        <?php endif; ?>

        <!-- hidden mirror for empty selection -->
        <input type="hidden" name="BSBORD_OPERATORS" id="BSBORD_OPERATORS"
               value="<?= View::e((string) ($values['BSBORD_OPERATORS'] ?? '')) ?>">

        <div style="margin-top:1.2rem">
            <button class="btn" type="submit">Сохранить</button>
        </div>
    </form>
</div>
