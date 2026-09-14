<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

interface ProviderInterface
{
    public function name(): string;

    /**
     * @param array{
     *   name: string,
     *   region?: string|null,
     *   cloud_init: string,
     *   comment?: string|null
     * } $opts
     */
    public function create(array $opts): ServerInfo;

    public function get(string $serverId): ServerInfo;

    /** @return list<ServerInfo> */
    public function list(): array;

    public function destroy(string $serverId): void;
}
