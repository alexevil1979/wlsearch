<?php

declare(strict_types=1);

/**
 * Multi-account Timeweb / Selectel + runs.provider_account_id
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS provider_accounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(32) NOT NULL COMMENT 'timeweb|selectel',
                name VARCHAR(128) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                credentials_json TEXT NOT NULL,
                config_json TEXT NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                last_used_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_pa_provider_enabled (provider, enabled),
                KEY idx_pa_sort (provider, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $cols = $pdo->query("SHOW COLUMNS FROM runs LIKE 'provider_account_id'")->fetch();
        if (!$cols) {
            $pdo->exec(
                'ALTER TABLE runs ADD COLUMN provider_account_id INT UNSIGNED NULL AFTER provider,
                 ADD KEY idx_runs_provider_account (provider_account_id)'
            );
        }

        // Seed from .env / settings if no accounts yet
        $count = (int) $pdo->query('SELECT COUNT(*) FROM provider_accounts')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $settings = [];
        try {
            foreach ($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (Throwable) {
        }

        $env = static function (string $key, string $default = '') use ($settings): string {
            if (isset($settings[$key]) && $settings[$key] !== '') {
                return $settings[$key];
            }
            $v = $_ENV[$key] ?? getenv($key);
            if ($v === false || $v === null || $v === '') {
                return $default;
            }
            return (string) $v;
        };

        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO provider_accounts
             (provider, name, enabled, credentials_json, config_json, sort_order, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, 0, ?, ?)'
        );

        $twToken = $env('TIMEWEB_API_TOKEN');
        if ($twToken !== '') {
            $creds = json_encode(['TIMEWEB_API_TOKEN' => $twToken], JSON_UNESCAPED_UNICODE);
            $cfg = [];
            foreach ([
                'TIMEWEB_API_BASE', 'TIMEWEB_PRESET_ID', 'TIMEWEB_OS_ID', 'TIMEWEB_AVAILABILITY_ZONE',
                'TIMEWEB_BANDWIDTH', 'TIMEWEB_PROJECT_ID', 'TIMEWEB_PRESET_COST_RUB',
                'TIMEWEB_ENSURE_IPV4', 'TIMEWEB_FLOATING_IP_ID', 'TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY',
                'TIMEWEB_CONFIGURATOR_ID', 'TIMEWEB_CPU', 'TIMEWEB_GPU', 'TIMEWEB_RAM_GB', 'TIMEWEB_DISK_GB',
            ] as $k) {
                $v = $env($k);
                if ($v !== '') {
                    $cfg[$k] = $v;
                }
            }
            if (!isset($cfg['TIMEWEB_API_BASE'])) {
                $cfg['TIMEWEB_API_BASE'] = 'https://api.timeweb.cloud/api/v1';
            }
            if (!isset($cfg['TIMEWEB_AVAILABILITY_ZONE'])) {
                $cfg['TIMEWEB_AVAILABILITY_ZONE'] = 'spb-3';
            }
            if (!isset($cfg['TIMEWEB_ENSURE_IPV4'])) {
                $cfg['TIMEWEB_ENSURE_IPV4'] = '1';
            }
            if (!isset($cfg['TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY'])) {
                $cfg['TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY'] = '1';
            }
            $ins->execute([
                'timeweb',
                'Timeweb (из .env)',
                $creds,
                json_encode($cfg, JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ]);
        }

        $selUser = $env('SELECTEL_USERNAME');
        $selPass = $env('SELECTEL_PASSWORD');
        if ($selUser !== '' && $selPass !== '') {
            $creds = json_encode([
                'SELECTEL_USERNAME' => $selUser,
                'SELECTEL_PASSWORD' => $selPass,
            ], JSON_UNESCAPED_UNICODE);
            $cfg = [];
            foreach ([
                'SELECTEL_AUTH_URL', 'SELECTEL_PROJECT_ID', 'SELECTEL_PROJECT_NAME',
                'SELECTEL_USER_DOMAIN_NAME', 'SELECTEL_PROJECT_DOMAIN_NAME',
                'SELECTEL_FLAVOR_ID', 'SELECTEL_IMAGE_ID', 'SELECTEL_NETWORK_ID',
                'SELECTEL_EXTERNAL_NET_ID', 'SELECTEL_REGION', 'SELECTEL_PRESET_COST_RUB',
            ] as $k) {
                $v = $env($k);
                if ($v !== '') {
                    $cfg[$k] = $v;
                }
            }
            $ins->execute([
                'selectel',
                'Selectel (из .env)',
                $creds,
                json_encode($cfg, JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ]);
        }
    },
];
