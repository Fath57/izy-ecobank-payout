<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * Where a bearer token is kept between calls, if anywhere.
 *
 * Tokens are per service and short-lived — the documentation says five minutes, and the
 * sandbox returns an `expires_in` that agrees. Caching them for an hour, which the
 * previous Ecobank API allowed, would make almost every payout start with a rejected
 * call and a retry.
 *
 * A host application should back this with whatever cache it already runs, shared across
 * processes: a per-process store means every worker fetches its own token, which works
 * but multiplies the round trips.
 */
interface TokenStore
{
    public function get(string $key): ?string;

    public function put(string $key, string $token, int $ttlSeconds): void;

    public function forget(string $key): void;
}
