<?php

declare(strict_types=1);

/**
 * Seed Timeweb VPS settings for configurator create (spb-3 / os 79 / cfg 11 / 1CPU 1GB 15GB).
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $defaults = [
            'TIMEWEB_AVAILABILITY_ZONE' => 'spb-3',
            'TIMEWEB_OS_ID' => '79',
            'TIMEWEB_CONFIGURATOR_ID' => '11',
            'TIMEWEB_CPU' => '1',
            'TIMEWEB_GPU' => '0',
            'TIMEWEB_RAM_GB' => '1',
            'TIMEWEB_DISK_GB' => '15',
            'TIMEWEB_BANDWIDTH' => '200',
            'TIMEWEB_PRESET_ID' => '', // empty → use configuration, not preset
            'TIMEWEB_ENSURE_IPV4' => '1',
            'TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY' => '1',
            'TIMEWEB_PRESET_COST_RUB' => '0',
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = \'\' OR setting_value IS NULL, VALUES(setting_value), setting_value), updated_at = NOW()'
        );
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    },
];
