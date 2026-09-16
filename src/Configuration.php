<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * What Ecobank hands a partner at onboarding, plus the gateway address.
 *
 * Two gates guard every call, and they are unrelated — confusing them costs an afternoon:
 *
 * - the **subscription key** goes in the `Ocp-Apim-Subscription-Key` header and says
 *   which product of the developer portal we are calling through. It is read from the
 *   portal's Profile page, per subscription.
 * - the **bearer token** says who we are and which service we may use. It is obtained
 *   from the authentication endpoint and lives about five minutes.
 *
 * A call missing either is refused, and the two refusals do not distinguish themselves.
 *
 * The **secret key never travels**: it only ever enters the two SHA-512 strings. The
 * publicKey does travel, in the body of the token request. The names look alike and the
 * roles are opposite.
 */
final class Configuration
{
    /**
     * @param  string  $baseUrl  gateway root, e.g. https://apimuat-gateway.ecobank.com
     * @param  string  $subscriptionKey  portal Profile → subscription → primary key
     * @param  string  $clientId  partner identifier, issued at onboarding
     * @param  string  $affiliateCode  Ecobank country code, e.g. EGH for Ghana
     * @param  string  $sourceCode  partner source code, issued at onboarding
     * @param  string  $publicKey  sent in the token request body
     * @param  string  $secretKey  never sent; hashed into both signatures
     * @param  string  $ipAddress  our address as the bank sees it. It is signed, so it
     *                             must be identical between the token request and the
     *                             payment it authorises. Configure it rather than detect
     *                             it: a container behind a proxy reports an internal
     *                             address that changes on restart, and the mismatch
     *                             surfaces only as a refused signature.
     * @param  string  $currency  ISO code of the transfer, e.g. XOF or GHS. A currency
     *                            the affiliate does not settle in is refused.
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $subscriptionKey,
        public readonly string $clientId,
        public readonly string $affiliateCode,
        public readonly string $sourceCode,
        public readonly string $publicKey,
        public readonly string $secretKey,
        public readonly string $ipAddress = '127.0.0.1',
        public readonly string $currency = 'XOF',
    ) {}

    public function signature(): Signature
    {
        return new Signature($this->secretKey);
    }

    /**
     * Refuses an incomplete configuration before the first call rather than after.
     *
     * An empty secret key produces a signature that is well formed and wrong, and the
     * bank answers with a generic rejection that sends everyone looking at the payload
     * instead of the configuration.
     *
     * @throws EcobankException when a value is missing
     */
    public function assertComplete(): void
    {
        $missing = [];

        foreach ([
            'baseUrl' => $this->baseUrl,
            'subscriptionKey' => $this->subscriptionKey,
            'clientId' => $this->clientId,
            'affiliateCode' => $this->affiliateCode,
            'sourceCode' => $this->sourceCode,
            'publicKey' => $this->publicKey,
            'secretKey' => $this->secretKey,
        ] as $name => $value) {
            if ($value === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new EcobankException('Incomplete configuration: ' . implode(', ', $missing));
        }
    }
}
