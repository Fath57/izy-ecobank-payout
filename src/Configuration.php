<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/** What Ecobank hands a partner at onboarding, plus the gateway address. */
final class Configuration
{
    /**
     * must be identical between the token request and the payment it authorises.
     *
     * @param  string  $baseUrl  gateway root, e.g. https://apimuat-gateway.ecobank.com
     * @param  string  $subscriptionKey  portal Profile → subscription → primary key
     * @param  string  $clientId  partner identifier, issued at onboarding
     * @param  string  $affiliateCode  Ecobank country code, e.g. EGH for Ghana
     * @param  string  $sourceCode  partner source code, issued at onboarding
     * @param  string  $publicKey  sent in the token request body
     * @param  string  $secretKey  never sent; hashed into both signatures
     * @param  string  $ipAddress  the address we declare; signed, but not verified by the bank
     * @param  string  $currency  ISO code of the transfer, e.g. XOF or GHS. A currency
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
