<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/** Where a bearer token is kept between calls, if anywhere. */
interface TokenStore
{
    public function get(string $key): ?string;

    public function put(string $key, string $token, int $ttlSeconds): void;

    public function forget(string $key): void;
}
