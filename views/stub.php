<?php
/** @var string $title */
/** @var string $message */
use Wlsearch\Support\View;
?>
<h1><?= View::e($title) ?></h1>
<div class="card">
    <p class="stub"><?= View::e($message) ?></p>
    <p class="muted" style="margin-bottom:0">Разделы навигации уже на месте — функционал подключится по фазам.</p>
</div>
