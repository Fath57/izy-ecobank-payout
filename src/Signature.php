<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * The two signatures every Ecobank call carries.
 *
 *     requestToken = SHA-512( clientId + affiliateCode + sourceCode + requestId
 *                           + requestType + ipAddress + secretKey )
 *
 *     secureHash   = SHA-512( <the same six> + requestToken
 *                           + <the endpoint's own fields> + secretKey )
 *
 * SHA-512, lower-case hex, 128 characters, over UTF-8 bytes.
 *
 * This is NOT an HMAC. The secret key is plainly concatenated at the end of the string
 * and the whole thing is hashed. A port that reaches for the host language's HMAC API
 * will produce a different digest.
 *
 * Three traps, each pinned by a case in vectors/signature.json:
 *
 * 1. requestToken feeds secureHash. Compute the first, then concatenate it into the
 *    second. Computing both in parallel from the same inputs yields two well-formed
 *    digests that the bank rejects together, with no indication of which is wrong.
 *
 * 2. The hashed order is not the JSON order. The payload starts `affiliateCode`,
 *    `clientId`; the hash starts `clientId`, `affiliateCode`. Concatenating the document
 *    top-down — which is exactly what the previous Ecobank API required — silently
 *    produces the wrong digest here.
 *
 * 3. The amount is hashed as a string, and that string must match what lands in the JSON
 *    body. See amountString().
 *
 * Whether the bank checks any of this depends on the client app, not on the environment.
 * The demo app in Ecobank's own documentation (CL001) issues a token however you sign —
 * measured 13 and 29 Sep 2026, a secret wrong by one character still returns SUCCESS. A
 * real onboarded app answers `Invalid SecureHash or Request Token Provided` to the same
 * mutation.
 *
 * So a green round trip against the demo credentials says nothing, and the vectors are
 * the only check that exists until real credentials arrive. Once they do, the bank itself
 * becomes the check: any error that is *not* about the hash means the signature passed.
 */
final class Signature
{
    /**
     * The six header values, in the order the formula concatenates them.
     *
     * Named here rather than read from the payload's own order: the payload orders them
     * differently, and relying on it is the mistake this list prevents.
     */
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
     * The payload signature: the header, then the request token, then the endpoint's own
     * values in the order its documentation lists them.
     *
     * Every endpoint has its own field list. The one for a domestic transfer is
     * receiverAccountNo + amountString + currency + description; a status query is just
     * the transaction reference. Reusing one endpoint's list for another is a silent
     * failure — the digest is well formed and refused.
     *
     * @param  array<string, string>  $header  the headerRequest, without requestToken
     * @param  list<string>  $fields  the endpoint's own values, in the documented order
     */
    public function secureHash(array $header, string $requestToken, array $fields = []): string
    {
        return $this->hash([...$this->headerValues($header), $requestToken, ...$fields]);
    }

    /**
     * How an amount enters the hashed string.
     *
     * The documentation writes `amountString`. Its rendering has to match the JSON body:
     * if the body carries 50000 and the signature hashes "50000.00", the bank hashes one
     * string and we hashed another, and the only feedback is "Invalid Request Token".
     *
     * In PHP, json_encode(50000.0) renders `50000`, and so does this. A port must check
     * the same equivalence in its own language before trusting it — several render a
     * float as "50000.0".
     */
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
