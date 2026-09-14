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
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <span class="brand">wlsearch</span>
        <p class="muted" style="text-align:center;margin-top:0">Админка поиска БС-IP</p>

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
</body>
</html>
