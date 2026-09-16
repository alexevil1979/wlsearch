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
    Несколько Timeweb / Selectel / Yandex Cloud. Галочками включаете, какие участвуют в лотерее.
    Run идут round-robin (LRU) по включённым — удобно обходить дневной лимит floating IP (~10/аккаунт).
</p>

<?php foreach (['timeweb' => 'Timeweb Cloud', 'selectel' => 'Selectel', 'yandex' => 'Yandex Cloud'] as $prov => $title): ?>
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
                        $zone = $cfg['TIMEWEB_AVAILABILITY_ZONE']
                            ?? $cfg['SELECTEL_REGION']
                            ?? $cfg['YANDEX_ZONE_ID']
                            ?? '';
                        $preset = $cfg['TIMEWEB_PRESET_ID'] ?? $cfg['SELECTEL_FLAVOR_ID'] ?? '';
                        if ($preset === '' && ($cfg['YANDEX_CORES'] ?? '') !== '') {
                            $preset = ($cfg['YANDEX_CORES'] ?? '') . 'c/' . ($cfg['YANDEX_MEMORY_GB'] ?? '') . 'G';
                        }
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
        $p = $editRow ? (string) $editRow['provider'] : 'yandex';
        $cfg = $editRow['_config'] ?? [];
        ?>
        <div class="form-grid">
        <div>
        <label for="provider">Провайдер</label>
        <select id="provider" name="provider" <?= $editRow ? 'disabled' : '' ?> onchange="(function(v){['tw','sel','yc'].forEach(function(x){document.getElementById(x+'-fields').style.display='none';});var m={timeweb:'tw',selectel:'sel',yandex:'yc'};document.getElementById(m[v]+'-fields').style.display='block';})(this.value)">
            <option value="yandex" <?= $p === 'yandex' ? 'selected' : '' ?>>yandex</option>
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
               value="<?= View::e((string) ($editRow['name'] ?? '')) ?>" placeholder="Yandex / Timeweb #2 / Selectel prod">
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

        <div id="yc-fields" style="display:<?= $p === 'yandex' ? 'block' : 'none' ?>">
            <h3>Yandex Cloud</h3>
            <p class="muted">Сервисный аккаунт → ключ (authorized key JSON) + folder + subnet. Публичный IPv4 через one-to-one NAT.</p>
            <div class="form-grid">
            <div class="span-2">
            <label for="YANDEX_SA_KEY_JSON">SA key JSON <?= $editRow ? '(пусто = не менять)' : '' ?></label>
            <textarea id="YANDEX_SA_KEY_JSON" name="YANDEX_SA_KEY_JSON" rows="6" autocomplete="off"
                      placeholder='{"id":"...","service_account_id":"...","private_key":"-----BEGIN PRIVATE KEY-----\\n..."}'></textarea>
            </div>
            <div>
            <label for="YANDEX_FOLDER_ID">Folder id</label>
            <input id="YANDEX_FOLDER_ID" name="YANDEX_FOLDER_ID" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_FOLDER_ID'] ?? '')) ?>" placeholder="b1g…">
            </div>
            <div>
            <label for="YANDEX_SUBNET_ID">Subnet id</label>
            <input id="YANDEX_SUBNET_ID" name="YANDEX_SUBNET_ID" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_SUBNET_ID'] ?? '')) ?>" placeholder="e9b…">
            </div>
            <div>
            <label for="YANDEX_ZONE_ID">Zone</label>
            <input id="YANDEX_ZONE_ID" name="YANDEX_ZONE_ID" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_ZONE_ID'] ?? 'ru-central1-a')) ?>">
            </div>
            <div>
            <label for="YANDEX_IMAGE_FAMILY">Image family</label>
            <input id="YANDEX_IMAGE_FAMILY" name="YANDEX_IMAGE_FAMILY" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_IMAGE_FAMILY'] ?? 'ubuntu-2204-lts')) ?>">
            </div>
            <div>
            <label for="YANDEX_IMAGE_ID">Image id (опц.)</label>
            <input id="YANDEX_IMAGE_ID" name="YANDEX_IMAGE_ID" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_IMAGE_ID'] ?? '')) ?>">
            </div>
            <div>
            <label for="YANDEX_PLATFORM_ID">Platform</label>
            <input id="YANDEX_PLATFORM_ID" name="YANDEX_PLATFORM_ID" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_PLATFORM_ID'] ?? 'standard-v3')) ?>">
            </div>
            <div>
            <label for="YANDEX_CORES">Cores</label>
            <input id="YANDEX_CORES" name="YANDEX_CORES" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_CORES'] ?? '2')) ?>">
            </div>
            <div>
            <label for="YANDEX_MEMORY_GB">RAM GB</label>
            <input id="YANDEX_MEMORY_GB" name="YANDEX_MEMORY_GB" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_MEMORY_GB'] ?? '2')) ?>">
            </div>
            <div>
            <label for="YANDEX_DISK_GB">Disk GB</label>
            <input id="YANDEX_DISK_GB" name="YANDEX_DISK_GB" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_DISK_GB'] ?? '15')) ?>">
            </div>
            <div>
            <label for="YANDEX_PREEMPTIBLE">Preemptible (1/0)</label>
            <input id="YANDEX_PREEMPTIBLE" name="YANDEX_PREEMPTIBLE" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_PREEMPTIBLE'] ?? '1')) ?>">
            </div>
            <div>
            <label for="YANDEX_PRESET_COST_RUB">Оценка ₽</label>
            <input id="YANDEX_PRESET_COST_RUB" name="YANDEX_PRESET_COST_RUB" type="text"
                   value="<?= View::e((string) ($cfg['YANDEX_PRESET_COST_RUB'] ?? '0')) ?>">
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
