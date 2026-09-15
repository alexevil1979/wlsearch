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
            'BS_TASK_TTL_SEC' => 'TTL BS-задачи (сек)',
            'PROVISION_TIMEOUT_SEC' => 'Timeout provision (сек)',
            'BOOTSTRAP_TIMEOUT_SEC' => 'Timeout bootstrap (сек)',
            'CONTROL_CHECK_TIMEOUT_SEC' => 'Timeout control (сек)',
            'BS_MODE_DEFAULT' => 'Дефолт BS при запуске (bsbord|agent|both)',
            'BSBORD_MIN_PASS' => 'Мин. число операторов БС с PASS',
        ];
        foreach ($labels as $key => $label):
        ?>
            <label for="<?= View::e($key) ?>"><?= View::e($label) ?></label>
            <input id="<?= View::e($key) ?>" name="<?= View::e($key) ?>" type="text"
                   value="<?= View::e((string) ($values[$key] ?? '')) ?>">
        <?php endforeach; ?>

        <h2 style="margin-top:1.4rem">Telegram</h2>
        <p class="muted" style="margin:0 0 0.75rem">
            Уведомления о PASS/FAIL. Прокси Bot API — как в botfabric (socks5h на локальный туннель).
            Пустой прокси = прямой доступ к <code>api.telegram.org</code>.
        </p>
        <label for="TELEGRAM_BOT_TOKEN">Токен бота (API)</label>
        <input id="TELEGRAM_BOT_TOKEN" name="TELEGRAM_BOT_TOKEN" type="password"
               value="<?= View::e((string) ($values['TELEGRAM_BOT_TOKEN'] ?? '')) ?>"
               autocomplete="off" placeholder="123456:AA…">
        <label for="TELEGRAM_CHAT_ID">Chat id</label>
        <input id="TELEGRAM_CHAT_ID" name="TELEGRAM_CHAT_ID" type="text"
               value="<?= View::e((string) ($values['TELEGRAM_CHAT_ID'] ?? '')) ?>"
               placeholder="-100… или личный id">
        <label for="TELEGRAM_PROXY">Прокси Bot API (как botfabric)</label>
        <input id="TELEGRAM_PROXY" name="TELEGRAM_PROXY" type="text"
               value="<?= View::e((string) (($values['TELEGRAM_PROXY'] ?? '') !== '' ? $values['TELEGRAM_PROXY'] : 'socks5h://127.0.0.1:1080')) ?>"
               placeholder="socks5h://127.0.0.1:1080">

        <h2 style="margin-top:1.4rem">Timeweb VPS</h2>
        <p class="muted" style="margin:0 0 0.75rem">
            Основной режим: <strong>preset</strong> (<code>TIMEWEB_PRESET_ID</code> + <code>OS_ID</code> + zone).
            Биллинг Cloud всегда почасовой. Токен только в <code>.env</code>.
            Configurator — запасной вариант, если Preset id пустой.
        </p>
        <?php
        $twLabels = [
            'TIMEWEB_PRESET_ID' => 'Preset id (обязательно для create)',
            'TIMEWEB_OS_ID' => 'OS id',
            'TIMEWEB_AVAILABILITY_ZONE' => 'Zone (availability_zone)',
            'TIMEWEB_BANDWIDTH' => 'Bandwidth (Мбит/с)',
            'TIMEWEB_PROJECT_ID' => 'Project id (опц.)',
            'TIMEWEB_PRESET_COST_RUB' => 'Оценка стоимости ₽ (лимиты)',
            'TIMEWEB_ENSURE_IPV4' => 'Заказывать IPv4 (1/0)',
            'TIMEWEB_FLOATING_IP_ID' => 'Pinned floating IP id/адрес',
            'TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY' => 'Удалять IP при destroy (0=reuse, лимит Timeweb ~10 create/сутки)',
            'TIMEWEB_CONFIGURATOR_ID' => 'Configurator id (если preset пуст)',
            'TIMEWEB_CPU' => 'CPU (только configurator)',
            'TIMEWEB_GPU' => 'GPU (только configurator)',
            'TIMEWEB_RAM_GB' => 'RAM ГБ (только configurator)',
            'TIMEWEB_DISK_GB' => 'Диск ГБ (только configurator)',
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
