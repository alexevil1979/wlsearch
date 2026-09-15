<?php
/** @var array<string, list<array<string,mixed>>> $byProvider */
/** @var string $csrf */
/** @var int $editId */
use Wlsearch\Support\View;
$editId = $editId ?? 0;
$editRow = null;
foreach ($byProvider as $list) {
    foreach ($list as $a) {
        if ((int) $a['id'] === $editId) {
            $editRow = $a;
            break 2;
        }
    }
}
?>
<h1>Аккаунты провайдеров</h1>
<p class="muted">
    Несколько Timeweb / Selectel. Галочками включаете, какие участвуют в лотерее.
    Run идут round-robin (LRU) по включённым — удобно обходить дневной лимит floating IP (~10/аккаунт).
</p>

<?php foreach (['timeweb' => 'Timeweb Cloud', 'selectel' => 'Selectel'] as $prov => $title): ?>
    <?php $list = $byProvider[$prov] ?? []; ?>
    <div class="card" id="<?= View::e($prov) ?>" style="margin-bottom:1.2rem">
        <h2 style="margin-top:0"><?= View::e($title) ?></h2>
        <?php if ($list === []): ?>
            <p class="muted">Пока нет аккаунтов — добавьте ниже (или migrate подтянет из .env).</p>
        <?php else: ?>
            <form method="post" action="/accounts/enabled">
                <?= $csrf ?>
                <input type="hidden" name="provider" value="<?= View::e($prov) ?>">
                <div style="display:flex;flex-direction:column;gap:0.55rem;margin:0.75rem 0">
                    <?php foreach ($list as $a): ?>
                        <?php
                        $id = (int) $a['id'];
                        $cfg = $a['_config'] ?? [];
                        $zone = $cfg['TIMEWEB_AVAILABILITY_ZONE'] ?? $cfg['SELECTEL_REGION'] ?? '';
                        $preset = $cfg['TIMEWEB_PRESET_ID'] ?? $cfg['SELECTEL_FLAVOR_ID'] ?? '';
                        ?>
                        <label style="display:flex;flex-wrap:wrap;gap:0.6rem 1rem;align-items:center;padding:0.45rem 0;border-bottom:1px solid rgba(127,127,127,0.25);cursor:pointer">
                            <span style="display:inline-flex;align-items:center;gap:0.4rem;min-width:14rem">
                                <input type="checkbox" name="acc[]" value="<?= $id ?>" <?= (int) $a['enabled'] ? 'checked' : '' ?>>
                                <strong>#<?= $id ?> <?= View::e((string) $a['name']) ?></strong>
                            </span>
                            <span class="muted"><?= View::e((string) $zone) ?></span>
                            <?php if ($preset !== ''): ?>
                                <span class="muted">preset/flavor <?= View::e((string) $preset) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($a['last_used_at'])): ?>
                                <span class="muted">used <?= View::e((string) $a['last_used_at']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($a['last_error'])): ?>
                                <span class="badge badge-err" title="<?= View::e((string) $a['last_error']) ?>">err</span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="btn" type="submit">Сохранить галочки <?= View::e($prov) ?></button>
            </form>
            <div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.75rem">
                <?php foreach ($list as $a): ?>
                    <?php $id = (int) $a['id']; ?>
                    <a class="btn btn-secondary" href="/accounts?edit=<?= $id ?>#edit" style="padding:0.2rem 0.5rem;font-size:0.8rem">edit #<?= $id ?></a>
                    <form method="post" action="/accounts/<?= $id ?>/delete" style="display:inline" onsubmit="return confirm('Удалить аккаунт #<?= $id ?>?')">
                        <?= $csrf ?>
                        <button type="submit" class="btn btn-danger" style="padding:0.2rem 0.5rem;font-size:0.8rem">del #<?= $id ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card form-wide" id="edit">
    <h2 style="margin-top:0"><?= $editRow ? 'Редактировать #' . (int) $editRow['id'] : 'Добавить аккаунт' ?></h2>
    <form method="post" action="<?= $editRow ? '/accounts/' . (int) $editRow['id'] : '/accounts' ?>">
        <?= $csrf ?>
        <?php
        $p = $editRow ? (string) $editRow['provider'] : 'timeweb';
        $cfg = $editRow['_config'] ?? [];
        ?>
        <div class="form-grid">
        <div>
        <label for="provider">Провайдер</label>
        <select id="provider" name="provider" <?= $editRow ? 'disabled' : '' ?> onchange="document.getElementById('tw-fields').style.display=this.value==='timeweb'?'block':'none';document.getElementById('sel-fields').style.display=this.value==='selectel'?'block':'none'">
            <option value="timeweb" <?= $p === 'timeweb' ? 'selected' : '' ?>>timeweb</option>
            <option value="selectel" <?= $p === 'selectel' ? 'selected' : '' ?>>selectel</option>
        </select>
        <?php if ($editRow): ?>
            <input type="hidden" name="provider" value="<?= View::e($p) ?>">
        <?php endif; ?>
        </div>
        <div>
        <label for="name">Имя</label>
        <input id="name" name="name" type="text" required maxlength="128"
               value="<?= View::e((string) ($editRow['name'] ?? '')) ?>" placeholder="Timeweb #2 / Selectel prod">
        </div>
        <div class="span-2">
        <label style="display:inline-flex;align-items:center;gap:0.4rem;margin:0.6rem 0">
            <input type="checkbox" name="enabled" value="1" <?= !$editRow || (int) ($editRow['enabled'] ?? 1) ? 'checked' : '' ?>>
            Включён в лотерею
        </label>
        </div>
        </div>

        <div id="tw-fields" style="display:<?= $p === 'timeweb' ? 'block' : 'none' ?>">
            <h3>Timeweb</h3>
            <div class="form-grid">
            <div class="span-2">
            <label for="TIMEWEB_API_TOKEN">API token <?= $editRow ? '(пусто = не менять)' : '' ?></label>
            <input id="TIMEWEB_API_TOKEN" name="TIMEWEB_API_TOKEN" type="password" autocomplete="off"
                   placeholder="<?= $editRow ? '••••' : 'токен' ?>">
            </div>
            <div>
            <label for="TIMEWEB_PRESET_ID">Preset id</label>
            <input id="TIMEWEB_PRESET_ID" name="TIMEWEB_PRESET_ID" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_PRESET_ID'] ?? '2611')) ?>">
            </div>
            <div>
            <label for="TIMEWEB_OS_ID">OS id</label>
            <input id="TIMEWEB_OS_ID" name="TIMEWEB_OS_ID" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_OS_ID'] ?? '79')) ?>">
            </div>
            <div>
            <label for="TIMEWEB_AVAILABILITY_ZONE">Zone</label>
            <input id="TIMEWEB_AVAILABILITY_ZONE" name="TIMEWEB_AVAILABILITY_ZONE" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_AVAILABILITY_ZONE'] ?? 'spb-3')) ?>">
            </div>
            <div>
            <label for="TIMEWEB_BANDWIDTH">Bandwidth</label>
            <input id="TIMEWEB_BANDWIDTH" name="TIMEWEB_BANDWIDTH" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_BANDWIDTH'] ?? '200')) ?>">
            </div>
            <div>
            <label for="TIMEWEB_PROJECT_ID">Project id</label>
            <input id="TIMEWEB_PROJECT_ID" name="TIMEWEB_PROJECT_ID" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_PROJECT_ID'] ?? '')) ?>">
            </div>
            <div>
            <label for="TIMEWEB_PRESET_COST_RUB">Оценка ₽</label>
            <input id="TIMEWEB_PRESET_COST_RUB" name="TIMEWEB_PRESET_COST_RUB" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_PRESET_COST_RUB'] ?? '700')) ?>">
            </div>
            <div>
            <label for="TIMEWEB_ENSURE_IPV4">Заказывать IPv4 (1/0)</label>
            <input id="TIMEWEB_ENSURE_IPV4" name="TIMEWEB_ENSURE_IPV4" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_ENSURE_IPV4'] ?? '1')) ?>">
            </div>
            <div>
            <label for="TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY">Удалять IP при destroy (1/0)</label>
            <input id="TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY" name="TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY'] ?? '1')) ?>">
            </div>
            <div class="span-2">
            <label for="TIMEWEB_API_BASE">API base</label>
            <input id="TIMEWEB_API_BASE" name="TIMEWEB_API_BASE" type="text" value="<?= View::e((string) ($cfg['TIMEWEB_API_BASE'] ?? 'https://api.timeweb.cloud/api/v1')) ?>">
            </div>
            </div>
        </div>

        <div id="sel-fields" style="display:<?= $p === 'selectel' ? 'block' : 'none' ?>">
            <h3>Selectel</h3>
            <div class="form-grid">
            <div>
            <label for="SELECTEL_USERNAME">Username</label>
            <input id="SELECTEL_USERNAME" name="SELECTEL_USERNAME" type="text" value="<?= View::e((string) ($editUsername ?? '')) ?>" placeholder="<?= $editRow ? 'пусто = не менять' : '' ?>" autocomplete="off">
            </div>
            <div>
            <label for="SELECTEL_PASSWORD">Password <?= $editRow ? '(пусто = не менять)' : '' ?></label>
            <input id="SELECTEL_PASSWORD" name="SELECTEL_PASSWORD" type="password" autocomplete="off">
            </div>
            <div class="span-2">
            <label for="SELECTEL_AUTH_URL">Auth URL</label>
            <input id="SELECTEL_AUTH_URL" name="SELECTEL_AUTH_URL" type="text" value="<?= View::e((string) ($cfg['SELECTEL_AUTH_URL'] ?? '')) ?>">
            </div>
            <div>
            <label for="SELECTEL_PROJECT_ID">Project id</label>
            <input id="SELECTEL_PROJECT_ID" name="SELECTEL_PROJECT_ID" type="text" value="<?= View::e((string) ($cfg['SELECTEL_PROJECT_ID'] ?? '')) ?>">
            </div>
            <div>
            <label for="SELECTEL_PROJECT_NAME">Project name</label>
            <input id="SELECTEL_PROJECT_NAME" name="SELECTEL_PROJECT_NAME" type="text" value="<?= View::e((string) ($cfg['SELECTEL_PROJECT_NAME'] ?? '')) ?>">
            </div>
            <div>
            <label for="SELECTEL_FLAVOR_ID">Flavor id</label>
            <input id="SELECTEL_FLAVOR_ID" name="SELECTEL_FLAVOR_ID" type="text" value="<?= View::e((string) ($cfg['SELECTEL_FLAVOR_ID'] ?? '')) ?>">
            </div>
            <div>
            <label for="SELECTEL_IMAGE_ID">Image id</label>
            <input id="SELECTEL_IMAGE_ID" name="SELECTEL_IMAGE_ID" type="text" value="<?= View::e((string) ($cfg['SELECTEL_IMAGE_ID'] ?? '')) ?>">
            </div>
            <div>
            <label for="SELECTEL_NETWORK_ID">Network id</label>
            <input id="SELECTEL_NETWORK_ID" name="SELECTEL_NETWORK_ID" type="text" value="<?= View::e((string) ($cfg['SELECTEL_NETWORK_ID'] ?? '')) ?>">
            </div>
            <div>
            <label for="SELECTEL_EXTERNAL_NET_ID">External net (floating)</label>
            <input id="SELECTEL_EXTERNAL_NET_ID" name="SELECTEL_EXTERNAL_NET_ID" type="text" value="<?= View::e((string) ($cfg['SELECTEL_EXTERNAL_NET_ID'] ?? '')) ?>">
            </div>
            <div>
            <label for="SELECTEL_REGION">Region</label>
            <input id="SELECTEL_REGION" name="SELECTEL_REGION" type="text" value="<?= View::e((string) ($cfg['SELECTEL_REGION'] ?? 'ru-9a')) ?>">
            </div>
            <div>
            <label for="SELECTEL_USER_DOMAIN_NAME">User domain</label>
            <input id="SELECTEL_USER_DOMAIN_NAME" name="SELECTEL_USER_DOMAIN_NAME" type="text" value="<?= View::e((string) ($cfg['SELECTEL_USER_DOMAIN_NAME'] ?? 'Default')) ?>">
            </div>
            <div>
            <label for="SELECTEL_PROJECT_DOMAIN_NAME">Project domain</label>
            <input id="SELECTEL_PROJECT_DOMAIN_NAME" name="SELECTEL_PROJECT_DOMAIN_NAME" type="text" value="<?= View::e((string) ($cfg['SELECTEL_PROJECT_DOMAIN_NAME'] ?? 'Default')) ?>">
            </div>
            <div>
            <label for="SELECTEL_PRESET_COST_RUB">Оценка ₽</label>
            <input id="SELECTEL_PRESET_COST_RUB" name="SELECTEL_PRESET_COST_RUB" type="text" value="<?= View::e((string) ($cfg['SELECTEL_PRESET_COST_RUB'] ?? '0')) ?>">
            </div>
            </div>
        </div>

        <div style="margin-top:1rem">
            <button class="btn" type="submit"><?= $editRow ? 'Сохранить' : 'Добавить' ?></button>
            <?php if ($editRow): ?>
                <a class="btn btn-secondary" href="/accounts">Отмена</a>
            <?php endif; ?>
        </div>
    </form>
</div>
