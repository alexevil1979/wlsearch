<?php
/** @var array{type:string,message:string}|null $flash */
/** @var bool $locked */
/** @var int $lockSeconds */
/** @var string $csrf */
use Wlsearch\Support\View;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — wlsearch</title>
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
<div class="login-page">
    <div class="login-box">
        <span class="brand">wlsearch</span>
        <p class="muted" style="text-align:center;margin-top:0">Админка поиска БС-IP</p>
        <div style="display:flex;justify-content:center;margin:0.75rem 0 1rem">
            <div class="theme-switch" role="group" aria-label="Тема">
                <button type="button" data-theme-set="dark" title="Тёмная">Тёмн</button>
                <button type="button" data-theme-set="light" title="Светлая">Светл</button>
                <button type="button" data-theme-set="slate" title="Slate">Slate</button>
            </div>
        </div>

        <?php if (!empty($flash)): ?>
            <div class="flash flash-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>">
                <?= View::e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($locked)): ?>
            <div class="flash flash-error">
                Слишком много попыток. Подождите <?= (int) $lockSeconds ?> сек.
            </div>
        <?php endif; ?>

        <form method="post" action="/login" autocomplete="username">
            <?= $csrf ?>
            <label for="login">Логин</label>
            <input id="login" name="login" type="text" required autofocus <?= !empty($locked) ? 'disabled' : '' ?>>

            <label for="password">Пароль</label>
            <input id="password" name="password" type="password" required <?= !empty($locked) ? 'disabled' : '' ?>>

            <div style="margin-top:1.1rem">
                <button class="btn" type="submit" style="width:100%" <?= !empty($locked) ? 'disabled' : '' ?>>Войти</button>
            </div>
        </form>
    </div>
</div>
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
