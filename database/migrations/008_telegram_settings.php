<?php

declare(strict_types=1);

/**
 * Telegram notify: bot token, chat id, Bot API proxy (как botfabric).
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = \'\' OR setting_value IS NULL, VALUES(setting_value), setting_value), updated_at = NOW()'
        );
        $defaults = [
            'TELEGRAM_BOT_TOKEN' => '',
            'TELEGRAM_CHAT_ID' => '',
            'TELEGRAM_PROXY' => 'socks5h://127.0.0.1:1080',
        ];
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    },
];
