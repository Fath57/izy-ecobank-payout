<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/** The three payloads, built without sending anything. */
final class Payload
{
    public function __construct(private readonly Configuration $config) {}

    /**
     * The header every payload carries, signed.
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
     * A domestic transfer: from the partner's pool account to an Ecobank account in the same country.
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

    /** Fifteen alphanumeric characters, the requestId field's limit. */
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
