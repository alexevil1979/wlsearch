<?php
/** @var array<string,string|null> $values */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<h1>Лимиты и настройки</h1>
<p class="muted">Значения в таблице <code>settings</code> перекрывают `.env`. Cloud-токены только в `.env`.</p>

<div class="card">
    <form method="post" action="/settings">
        <?= $csrf ?>
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
            'BSBORD_API_TOKEN' => 'bsbord API token (bsk_live_…)',
            'BSBORD_OPERATORS' => 'bsbord operators filter (пусто = все dpi=on)',
            'BS_MODE_DEFAULT' => 'Дефолт BS: agent|bsbord|both',
        ];
        foreach ($labels as $key => $label):
        ?>
            <label for="<?= View::e($key) ?>"><?= View::e($label) ?> <span class="muted">(<?= View::e($key) ?>)</span></label>
            <input id="<?= View::e($key) ?>" name="<?= View::e($key) ?>" type="text"
                   value="<?= View::e((string) ($values[$key] ?? '')) ?>">
        <?php endforeach; ?>

        <div style="margin-top:1.2rem">
            <button class="btn" type="submit">Сохранить</button>
        </div>
    </form>
</div>
