<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * The three payloads, built without sending anything.
 *
 * Separated from transport on purpose: a signed payload has to be readable without being
 * emitted — a payment order is not something to fire in order to find out what it looks
 * like. It is also what makes the signatures testable, and they have to be: the demo
 * credentials everyone starts with do not check them (see Signature).
 *
 * Every payload has the same two-part shape: a `headerRequest` carrying the partner's
 * identity and the request token, and then the endpoint's own fields plus `secureHash`.
 */
final class Payload
{
    public function __construct(private readonly Configuration $config) {}

    /**
     * The header every payload carries, signed.
     *
     * Built here because five of its six values are the partner's identity, and because
     * the request token derives from the whole of it. Leaving that to each caller is how
     * one endpoint ends up signing a different set of fields than another.
     *
     * @param  string|null  $requestId  reuse an existing one on a retry; see newRequestId()
     * @return array<string, string>
     */
    public function header(string $requestType, ?string $requestId = null): array
    {
        $header = [
            'affiliateCode' => $this->config->affiliateCode,
            'clientId' => $this->config->clientId,
            'sourceCode' => $this->config->sourceCode,
            'requestId' => $requestId ?? self::newRequestId(),
            'ipAddress' => $this->config->ipAddress,
            'requestType' => $requestType,
        ];

        return $header + ['requestToken' => $this->config->signature()->requestToken($header)];
    }

    /**
     * The token request.
     *
     * `serviceCode` is chosen when asking for the token, not when spending it: a token
     * minted for DOMESTIC cannot pay a bill. Cache tokens per service, never globally.
     *
     * Signed fields, after the header and the request token: publicKey, then serviceCode.
     *
     * @return array<string, mixed>
     */
    public function token(string $serviceCode = ServiceCode::DOMESTIC): array
    {
        $header = $this->header(RequestType::GET_API_TOKEN);

        return [
            'headerRequest' => $header,
            'publicKey' => $this->config->publicKey,
            'serviceCode' => $serviceCode,
            'secureHash' => $this->config->signature()->secureHash(
                $header,
                $header['requestToken'],
                [$this->config->publicKey, $serviceCode],
            ),
        ];
    }

    /**
     * A domestic transfer: from the partner's pool account to an Ecobank account in the
     * same country.
     *
     * The debited account appears nowhere — it is attached to the clientId. The payload
     * says only where the money goes. That is a real difference from the previous
     * Ecobank API, which named both sides.
     *
     * Signed fields, after the header and the request token, in this exact order:
     * receiverAccountNo, amountString, currency, description.
     *
     * @return array<string, mixed>
     */
    public function domesticTransfer(
        string $receiverAccountNo,
        float $amount,
        string $description,
        ?string $requestId = null,
    ): array {
        $header = $this->header(RequestType::DOMESTIC_TRANSFER, $requestId);

        return [
            'headerRequest' => $header,
            'secureHash' => $this->config->signature()->secureHash($header, $header['requestToken'], [
                $receiverAccountNo,
                Signature::amountString($amount),
                $this->config->currency,
                $description,
            ]),
            'receiverAccountNo' => $receiverAccountNo,
            'amount' => $amount,
            'currency' => $this->config->currency,
            'description' => $description,
        ];
    }

    /**
     * What became of a transfer already ordered.
     *
     * Queried by the reference the bank returned in `data.transactionReference`, not by
     * the requestId we minted. Keeping only ours leaves a transfer whose fate cannot be
     * asked about.
     *
     * @return array<string, mixed>
     */
    public function transactionStatus(string $transactionReference): array
    {
        $header = $this->header(RequestType::TRANSACTION_STATUS);

        return [
            'headerRequest' => $header,
            'secureHash' => $this->config->signature()->secureHash(
                $header,
                $header['requestToken'],
                [$transactionReference],
            ),
            'transactionReference' => $transactionReference,
        ];
    }

    /**
     * Fifteen alphanumeric characters, the requestId field's limit.
     *
     * Reusing the same one on a retry is what stops a merchant being paid twice when an
     * answer is lost: the bank recognises the same order. Minting a fresh one would look
     * like a second transfer, and the first is already on its way. Store it against your
     * own transaction *before* the call, never after.
     */
    public static function newRequestId(string $prefix = 'IZY'): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $suffix = '';

        for ($i = strlen($prefix); $i < 15; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix . $suffix;
    }
}
