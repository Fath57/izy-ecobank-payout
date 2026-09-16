<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * The three calls a payout integration makes, in the order it makes them.
 *
 *     $client = new Client($configuration);
 *
 *     $order  = $client->domesticTransfer('1441002006858', 10.0, 'Payout 42');
 *     $ref    = $order['transactionReference'];   // store this, not your own requestId
 *     $status = $client->transactionStatus($ref);
 *
 * Four behaviours worth reproducing in any port, none of which is visible in the API
 * schema:
 *
 * 1. **The verdict is in the body.** This API answers HTTP 200 to a refused request and
 *    puts the reason in `headerResponse.responseCode`; "000" is the only success.
 *
 * 2. **An acknowledgement is not a confirmation.** A successful transfer call means the
 *    order was accepted, not that the money moved. Do not mark a payout as sent here —
 *    poll transactionStatus() and let that decide.
 *
 * 3. **Mint the requestId before the call and keep it.** If the answer is lost you must
 *    retry with the same one; a fresh one looks like a second transfer.
 *
 * 4. **Tokens are per service and expire in minutes.** Cache by serviceCode, drop on a
 *    401, and retry exactly once. Looping would spin forever on a revoked credential.
 */
final class Client
{
    public const PATH_TOKEN = '/corp-auth/api/v2/integration/auth/app/token';

    public const PATH_DOMESTIC_TRANSFER = '/corp-payment/api/v2/payment/domestic';

    public const PATH_TRANSACTION_STATUS = '/corp-payment/api/v2/integration/payment/status';

    /**
     * Well inside the documented five minutes.
     *
     * The gap absorbs the time between minting a token and spending it; an expiry met
     * mid-batch costs one retry rather than a failed payout.
     */
    public const TOKEN_TTL_SECONDS = 180;

    private readonly Payload $payload;

    public function __construct(
        private readonly Configuration $config,
        private readonly ?Transport $transport = null,
        private readonly ?TokenStore $tokens = null,
    ) {
        $this->config->assertComplete();
        $this->payload = new Payload($config);
    }

    public function payload(): Payload
    {
        return $this->payload;
    }

    /**
     * A bearer token for one service, reused while it is fresh.
     */
    public function token(string $serviceCode = ServiceCode::DOMESTIC): string
    {
        $store = $this->tokens ?? $this->defaultTokens();
        $key = 'ecobank.token.' . strtolower($serviceCode);

        if ($cached = $store->get($key)) {
            return $cached;
        }

        $response = $this->send(self::PATH_TOKEN, $this->payload->token($serviceCode));
        $token = (string) ($response->data()['access_token'] ?? '');

        if ($token === '') {
            throw new EcobankException(
                'Ecobank returned no access token: ' . $response->responseDescription(),
                $response->responseCode(),
                $response->status,
            );
        }

        $store->put($key, $token, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    /**
     * Orders a transfer from the pool account to an Ecobank account in the same country.
     *
     * @param  string|null  $requestId  pass the one already stored when retrying
     * @return array<string, mixed>  the bank's `data`: transactionReference, externalRefNo
     */
    public function domesticTransfer(
        string $receiverAccountNo,
        float $amount,
        string $description,
        ?string $requestId = null,
    ): array {
        $body = $this->payload->domesticTransfer($receiverAccountNo, $amount, $description, $requestId);

        return $this->authenticated(self::PATH_DOMESTIC_TRANSFER, $body)->data();
    }

    /**
     * Asks what became of an order already sent.
     *
     * Treat anything that is not an explicit failure as still pending: an unknown
     * reference and a transfer in flight look the same from here, and calling either a
     * failure would refund a merchant whose money is on its way.
     *
     * @return array<string, mixed>
     */
    public function transactionStatus(string $transactionReference): array
    {
        $body = $this->payload->transactionStatus($transactionReference);

        return $this->authenticated(self::PATH_TRANSACTION_STATUS, $body)->data();
    }

    /**
     * Sends a signed payload with a bearer token, renewing it once on a 401.
     *
     * @param  array<string, mixed>  $body
     */
    private function authenticated(string $path, array $body, string $serviceCode = ServiceCode::DOMESTIC): TransportResponse
    {
        $response = $this->send($path, $body, $this->token($serviceCode));

        if ($response->status === 401) {
            ($this->tokens ?? $this->defaultTokens())->forget('ecobank.token.' . strtolower($serviceCode));
            $response = $this->send($path, $body, $this->token($serviceCode));
        }

        if (! $response->isSuccess()) {
            throw new EcobankException(
                $response->responseDescription() !== ''
                    ? $response->responseDescription()
                    : sprintf('Ecobank refused %s (HTTP %d)', $path, $response->status),
                $response->responseCode(),
                $response->status,
            );
        }

        return $response;
    }

    /** @param array<string, mixed> $body */
    private function send(string $path, array $body, ?string $token = null): TransportResponse
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Ocp-Apim-Subscription-Key' => $this->config->subscriptionKey,
        ];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return ($this->transport ?? new CurlTransport())
            ->post(rtrim($this->config->baseUrl, '/') . $path, $headers, $body);
    }

    private function defaultTokens(): TokenStore
    {
        static $store = null;

        return $store ??= new InMemoryTokenStore();
    }
}
