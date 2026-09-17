<?php
/** @var list<array<string,mixed>> $items */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<div class="page-head">
    <div>
        <h1>Checked IPs</h1>
        <p class="muted">Уже проверенные адреса. Повторная выдача → сразу destroy (кроме избранных: /24 не травит).</p>
    </div>
</div>

<div class="card" style="padding:0.85rem 1rem;margin-bottom:1rem">
    <form method="post" action="/checked-ips/clear-prefix" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:end"
          onsubmit="return confirm('Снять все fail-* для префикса? IP снова пойдут в полную BS-проверку.')">
        <?= $csrf ?>
        <div>
            <label for="prefix" class="muted" style="font-size:0.8rem">Снять fail по префиксу</label>
            <input id="prefix" name="prefix" type="text" value="84.201." placeholder="84.201."
                   style="min-width:10rem" required>
        </div>
        <button class="btn btn-danger" type="submit">forget fails</button>
    </form>
    <p class="muted" style="margin:0.5rem 0 0;font-size:0.8rem">
        Пример: <code>84.201.</code> — все fail_bs/fail_* в этой подсети. PASS-записи не трогает.
    </p>
</div>

<div class="card table-wrap">
    <?php if ($items === []): ?>
        <p class="muted" style="margin:0">Пока пусто — после migrate появятся IP из прошлых runs.</p>
    <?php else: ?>
        <table class="data">
            <thead>
            <tr>
                <th>IP</th>
                <th>Verdict</th>
                <th>Provider</th>
                <th>ASN</th>
                <th>Run</th>
                <th>Detail</th>
                <th>Updated</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <?php
                $v = (string) ($it['verdict'] ?? '');
                $badge = $v === 'pass' ? 'badge-ok' : 'badge-err';
                $detail = (string) ($it['detail'] ?? '');
                ?>
                <tr>
                    <td class="cell-narrow"><code><?= View::e((string) $it['ipv4']) ?></code></td>
                    <td class="cell-narrow"><span class="badge <?= $badge ?>"><?= View::e($v) ?></span></td>
                    <td class="muted cell-narrow"><?= View::e((string) ($it['provider'] ?? '—')) ?></td>
                    <td class="muted cell-narrow"><?= $it['asn'] ? 'AS' . (int) $it['asn'] : '—' ?></td>
                    <td class="muted cell-narrow"><?= $it['run_id'] ? '#' . (int) $it['run_id'] : '—' ?></td>
                    <td class="cell-error" title="<?= View::e($detail) ?>"><?= View::e($detail) ?></td>
                    <td class="muted cell-narrow"><?= View::e((string) ($it['updated_at'] ?? '')) ?></td>
                    <td class="cell-actions">
                        <form method="post" action="/checked-ips/<?= rawurlencode((string) $it['ipv4']) ?>/delete" onsubmit="return confirm('Удалить из кэша?')">
                            <?= $csrf ?>
                            <button class="btn btn-secondary btn-sm" type="submit">forget</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
