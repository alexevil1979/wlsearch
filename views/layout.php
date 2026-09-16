<?php
/** @var string $content */
/** @var string $title */
/** @var array|null $user */
/** @var string $csrf */
/** @var string $nav */
/** @var array{type:string,message:string}|null $flash */
use Wlsearch\Support\View;
$nav = $nav ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e(($title ?? 'wlsearch') . ' — wlsearch') ?></title>
    <script>
    (function () {
        try {
            var t = localStorage.getItem('wlsearch-theme') || 'dark';
            if (['dark', 'light', 'slate'].indexOf(t) < 0) t = 'dark';
            document.documentElement.setAttribute('data-theme', t);
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
    </script>
    <link rel="stylesheet" href="/assets/app.css?v=5">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/dashboard">wlsearch</a>
    <nav class="nav">
        <a href="/dashboard" class="<?= $nav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="/runs/new" class="<?= $nav === 'runs' ? 'active' : '' ?>">Запуск</a>
        <a href="/runs" class="<?= $nav === 'runs' ? 'active' : '' ?>">Runs</a>
        <a href="/inventory" class="<?= $nav === 'inventory' ? 'active' : '' ?>">Inventory</a>
        <a href="/checked-ips" class="<?= $nav === 'checked' ? 'active' : '' ?>">Checked</a>
        <a href="/devices" class="<?= $nav === 'devices' ? 'active' : '' ?>">Agents</a>
        <a href="/accounts" class="<?= $nav === 'accounts' ? 'active' : '' ?>">Аккаунты</a>
        <a href="/settings" class="<?= $nav === 'settings' ? 'active' : '' ?>">Настройки</a>
        <a href="/logs" class="<?= $nav === 'logs' ? 'active' : '' ?>">Логи</a>
        <a href="/blacklist" class="<?= $nav === 'blacklist' ? 'active' : '' ?>">Blacklist</a>
        <a href="/favorites" class="<?= $nav === 'favorites' ? 'active' : '' ?>">Избранные</a>
    </nav>
    <div class="topbar-user">
        <div class="theme-switch" role="group" aria-label="Тема">
            <button type="button" data-theme-set="dark" title="Тёмная">Тёмн</button>
            <button type="button" data-theme-set="light" title="Светлая">Светл</button>
            <button type="button" data-theme-set="slate" title="Slate">Slate</button>
        </div>
        <?php if (!empty($user)): ?>
            <span class="muted"><?= View::e($user['login'] ?? '') ?></span>
            <form method="post" action="/logout" style="display:inline;margin:0">
                <?= $csrf ?? '' ?>
                <button type="submit" class="btn btn-secondary btn-sm">Выход</button>
            </form>
        <?php endif; ?>
    </div>
</header>
<main class="wrap">
    <?php if (!empty($flash)): ?>
        <div class="flash flash-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>">
            <?= View::e($flash['message']) ?>
        </div>
    <?php endif; ?>
    <?= $content ?>
</main>
<script>
(function () {
    var key = 'wlsearch-theme';
    var allowed = { dark: 1, light: 1, slate: 1 };
    function apply(t) {
        if (!allowed[t]) t = 'dark';
        document.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem(key, t); } catch (e) {}
        document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-theme-set') === t);
        });
    }
    var cur = 'dark';
    try { cur = localStorage.getItem(key) || 'dark'; } catch (e) {}
    apply(cur);
    document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            apply(btn.getAttribute('data-theme-set'));
        });
    });
})();
</script>
</body>
</html>
