<?php

declare(strict_types=1);

namespace Izy\EcobankPayout\Tests;

use Izy\EcobankPayout\Client;
use Izy\EcobankPayout\Configuration;
use Izy\EcobankPayout\InMemoryTokenStore;
use Izy\EcobankPayout\Transport;
use Izy\EcobankPayout\TokenStore;
use Izy\EcobankPayout\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who a cached token belongs to.
 *
 * A token is minted for one clientId, under one affiliate and one source code. Caching it
 * under the service code alone lets a second set of credentials read the first one's token
 * out of the store and spend it — the calls would go out signed as one partner and
 * authorised as another.
 *
 * This is not hypothetical. The TokenStore seam exists so the cache can be Redis, shared
 * by every worker in a fleet, and a partner integration holds one set of credentials per
 * affiliate.
 */
class ClientTokenCacheTest extends TestCase
{
    public function test_two_identities_do_not_share_a_token(): void
    {
        $transport = new RecordingTransport();
        $store = new InMemoryTokenStore();

        (new Client($this->config('CL001', 'EGH'), $transport, $store))->token();
        (new Client($this->config('FEDAPA262660565', 'EBJ'), $transport, $store))->token();

        $this->assertCount(2, $transport->calls, 'Le second client a repris le jeton du premier');
        $this->assertSame('CL001', $transport->calls[0]['headerRequest']['clientId']);
        $this->assertSame('FEDAPA262660565', $transport->calls[1]['headerRequest']['clientId']);
    }

    public function test_the_same_identity_reuses_its_token(): void
    {
        $transport = new RecordingTransport();
        $store = new InMemoryTokenStore();

        (new Client($this->config('CL001', 'EGH'), $transport, $store))->token();
        (new Client($this->config('CL001', 'EGH'), $transport, $store))->token();

        $this->assertCount(1, $transport->calls, 'Le jeton aurait dû être réutilisé');
    }

    /**
     * Two clients built without a store of their own share nothing.
     *
     * The default store used to be a function-level static, so every Client in the
     * process drew from one cache — including clients built with different credentials.
     */
    public function test_clients_without_a_store_do_not_share_one(): void
    {
        $transport = new RecordingTransport();

        (new Client($this->config('CL001', 'EGH'), $transport))->token();
        (new Client($this->config('CL001', 'EGH'), $transport))->token();

        $this->assertCount(2, $transport->calls, 'Deux clients distincts ont partagé un cache');
    }

    public function test_the_cache_key_never_carries_the_secret(): void
    {
        $store = new SpyingTokenStore();

        (new Client($this->config('CL001', 'EGH'), new RecordingTransport(), $store))->token();

        foreach ($store->keys as $key) {
            $this->assertStringNotContainsString('secret-key-for-tests', $key);
        }
        $this->assertNotEmpty($store->keys);
    }

    /**
     * An expired token is refused with 403, not 401.
     *
     * Measured 01/10/2026: a token five hours past its expiry answers 403 "Access denied",
     * the same as an invalid one. A client that renews only on 401 never renews, and every
     * call fails until the cache entry lapses on its own.
     */
    #[DataProvider('tokenRejections')]
    public function test_a_rejected_token_is_renewed_once(int $status): void
    {
        $transport = new RejectingTransport($status);

        try {
            (new Client($this->config('CL001', 'EGH'), $transport))->domesticTransfer('1', 1.0, 'test');
        } catch (\Throwable) {
            // The second attempt is refused too; what is under test is that it happened.
        }

        $this->assertSame(
            ['token', 'call', 'token', 'call'],
            $transport->kinds,
            "HTTP $status did not trigger a token renewal"
        );
    }

    /** @return array<string, array{int}> */
    public static function tokenRejections(): array
    {
        return ['401 Unauthorized' => [401], '403 Access denied' => [403]];
    }

    private function config(string $clientId, string $affiliate): Configuration
    {
        return new Configuration(
            baseUrl: 'https://bank.test',
            subscriptionKey: 'subscription-for-tests',
            clientId: $clientId,
            affiliateCode: $affiliate,
            sourceCode: 'SOURCE',
            publicKey: 'public-key-for-tests',
            secretKey: 'secret-key-for-tests',
        );
    }
}

final class RecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function post(string $url, array $headers, array $body): TransportResponse
    {
        $this->calls[] = $body;

        return new TransportResponse(200, [
            'headerResponse' => ['responseCode' => '000', 'responseMessage' => 'SUCCESS'],
            'data' => ['access_token' => 'token-' . count($this->calls)],
        ]);
    }
}

final class SpyingTokenStore implements TokenStore
{
    /** @var list<string> */
    public array $keys = [];

    private InMemoryTokenStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryTokenStore();
    }

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function put(string $key, string $token, int $ttlSeconds): void
    {
        $this->keys[] = $key;
        $this->inner->put($key, $token, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->inner->forget($key);
    }
}

/** Answers every call with the same token-rejection status, recording what was asked. */
final class RejectingTransport implements Transport
{
    /** @var list<string> */
    public array $kinds = [];

    public function __construct(private readonly int $status) {}

    public function post(string $url, array $headers, array $body): TransportResponse
    {
        if (str_contains($url, '/auth/app/token')) {
            $this->kinds[] = 'token';

            return new TransportResponse(200, [
                'headerResponse' => ['responseCode' => '000'],
                'data' => ['access_token' => 'token-' . count($this->kinds)],
            ]);
        }

        $this->kinds[] = 'call';

        return new TransportResponse($this->status, [
            'headerResponse' => ['responseCode' => (string) $this->status, 'responseDesc' => 'Access denied'],
        ]);
    }
}
