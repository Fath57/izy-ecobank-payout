<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/** What came back: the HTTP status, and the decoded body. */
final class TransportResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        /** Kept verbatim: an HTML error page decodes to nothing, and the text is the only clue. */
        public readonly string $raw = '',
    ) {}

    public function responseCode(): string
    {
        return (string) ($this->body['headerResponse']['responseCode'] ?? '');
    }

    public function responseDescription(): string
    {
        return (string) ($this->body['headerResponse']['responseDesc'] ?? '');
    }

    /** The bank's verdict, which is not the HTTP status. */
    public function isSuccess(): bool
    {
        return $this->responseCode() === '000';
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $data = $this->body['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
