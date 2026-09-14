<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Device\DeviceService;
use Wlsearch\Task\TaskService;

final class AgentController
{
    public function nextTask(): void
    {
        $device = $this->authDevice();
        if ($device === null) {
            $this->json(['error' => 'unauthorized'], 401);
            return;
        }

        $svc = new DeviceService();
        $svc->touch((int) $device['id'], [
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $task = (new TaskService())->claimNext((int) $device['id']);
        if ($task === null) {
            http_response_code(204);
            return;
        }

        $this->json([
            'task_id' => (int) $task['id'],
            'run_id' => (int) $task['run_id'],
            'target_ipv4' => $task['target_ipv4'],
            'probe_marker' => $task['probe_marker'] ?? 'WL_PROBE_OK',
            'expires_at' => $task['expires_at'] ?? null,
            'instructions' => [
                'require_cellular' => true,
                'forbid_wifi' => true,
                'forbid_vpn' => true,
                'method' => 'GET',
                'url' => 'http://' . $task['target_ipv4'] . '/',
            ],
        ]);
    }

    public function result(string $taskId): void
    {
        $device = $this->authDevice();
        if ($device === null) {
            $this->json(['error' => 'unauthorized'], 401);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        if (!is_array($payload)) {
            $this->json(['error' => 'invalid_json'], 400);
            return;
        }

        try {
            (new DeviceService())->touch((int) $device['id']);
            (new TaskService())->submitResult((int) $taskId, (int) $device['id'], $payload);
            $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            $http = in_array($code, [403, 404, 409], true) ? (int) $code : 400;
            $this->json(['error' => $e->getMessage()], $http);
        }
    }

    public function heartbeat(): void
    {
        $device = $this->authDevice();
        if ($device === null) {
            $this->json(['error' => 'unauthorized'], 401);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        (new DeviceService())->touch((int) $device['id'], $payload);
        $this->json([
            'ok' => true,
            'device_id' => (int) $device['id'],
            'name' => $device['name'],
            'operator' => $device['operator'],
            'server_time' => gmdate('c'),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function authDevice(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        return (new DeviceService())->authenticateBearer(is_string($header) ? $header : null);
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
