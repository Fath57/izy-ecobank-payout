<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * A dependency-free Transport, so the package runs on its own.
 *
 * Fine for a script or a test; a host application should implement Transport over
 * whatever client it already uses, and keep its own retry and observability policy.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10,
    ) {}

    public function post(string $url, array $headers, array $body): TransportResponse
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        curl_close($handle);

        if ($raw === false) {
            /*
             * A transport failure is not a refusal.
             *
             * The order may well have reached the bank and been executed; only the
             * answer was lost. The caller must reuse the same requestId on a retry
             * rather than mint a new one, or the merchant is paid twice.
             */
            throw new EcobankException('Ecobank unreachable: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);

        return new TransportResponse($status, is_array($decoded) ? $decoded : [], (string) $raw);
    }
}
