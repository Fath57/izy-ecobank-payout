<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * A TokenStore that lives as long as the process.
 *
 * The default, so the package works with no infrastructure. It is enough for a script or
 * a test and wrong for a fleet of workers, each of which would hold its own token.
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $tokens = [];

    public function get(string $key): ?string
    {
        $entry = $this->tokens[$key] ?? null;

        if ($entry === null || $entry['expiresAt'] <= time()) {
            return null;
        }

        return $entry['token'];
    }

    public function put(string $key, string $token, int $ttlSeconds): void
    {
        $this->tokens[$key] = ['token' => $token, 'expiresAt' => time() + $ttlSeconds];
    }

    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
