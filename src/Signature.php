<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * The two SHA-512 signatures every call carries. Lower-case hex, 128 chars, UTF-8.
 *
 *     requestToken = SHA512( HEADER + secretKey )
 *     secureHash   = SHA512( HEADER + requestToken + <endpoint fields> + secretKey )
 *     HEADER       = clientId + affiliateCode + sourceCode + requestId + requestType + ipAddress
 *
 * Not an HMAC: the secret is concatenated, then the whole string is hashed.
 *
 * Traps, each pinned by a case in vectors/signature.json: requestToken goes inside
 * secureHash; the hashed field order is not the JSON order; the amount is hashed as text.
 */
final class Signature
{
    /** The concatenation order, which differs from the payload's own order. */
    public const HEADER_ORDER = [
        'clientId', 'affiliateCode', 'sourceCode', 'requestId', 'requestType', 'ipAddress',
    ];

    public function __construct(private readonly string $secretKey) {}

    /**
     * @param  array<string, string>  $header  the headerRequest, without requestToken
     */
    public function requestToken(array $header): string
    {
        return $this->hash($this->headerValues($header));
    }

    /**
     * The header, then the request token, then the endpoint's own values.
     *
     * @param  array<string, string>  $header  the headerRequest, without requestToken
     * @param  list<string>  $fields  the endpoint's own values, in the documented order
     */
    public function secureHash(array $header, string $requestToken, array $fields = []): string
    {
        return $this->hash([...$this->headerValues($header), $requestToken, ...$fields]);
    }

    /** The hashed rendering of an amount. It must equal what the JSON body carries. */
    public static function amountString(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, string>  $header
     * @return list<string>
     */
    private function headerValues(array $header): array
    {
        return array_map(
            static fn (string $key): string => (string) ($header[$key] ?? ''),
            self::HEADER_ORDER
        );
    }

    /** @param  list<string>  $parts */
    private function hash(array $parts): string
    {
        return hash('sha512', implode('', $parts) . $this->secretKey);
    }
}
