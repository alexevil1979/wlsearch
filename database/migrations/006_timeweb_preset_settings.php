<?php

declare(strict_types=1);

/**
 * Prefer Timeweb preset create; seed PRESET_ID/OS/zone into settings from common defaults.
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        // Overwrite configurator-first defaults from 005 with preset-first values.
        // Do not wipe a non-empty PRESET_ID the operator already set.
        $defaults = [
            'TIMEWEB_AVAILABILITY_ZONE' => 'spb-3',
            'TIMEWEB_OS_ID' => '99',
            'TIMEWEB_PRESET_ID' => '4795',
            'TIMEWEB_BANDWIDTH' => '200',
            'TIMEWEB_CONFIGURATOR_ID' => '',
            'TIMEWEB_CPU' => '1',
            'TIMEWEB_GPU' => '0',
            'TIMEWEB_RAM_GB' => '1',
            'TIMEWEB_DISK_GB' => '15',
        ];

        $get = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $ins = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        foreach ($defaults as $k => $v) {
            if ($k === 'TIMEWEB_PRESET_ID') {
                $get->execute([$k]);
                $cur = $get->fetchColumn();
                // Keep existing non-empty preset; otherwise seed 4795
                if (is_string($cur) && trim($cur) !== '' && (int) $cur > 0) {
                    continue;
                }
            }
            if ($k === 'TIMEWEB_OS_ID') {
                $get->execute([$k]);
                $cur = $get->fetchColumn();
                // If still the configurator-era os=79, move to common Ubuntu preset OS 99
                if ($cur !== false && (string) $cur !== '' && (string) $cur !== '79') {
                    continue;
                }
            }
            $ins->execute([$k, $v]);
        }
    },
];
