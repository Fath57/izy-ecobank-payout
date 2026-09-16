<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * Any refusal from the bank, or any configuration that would produce one.
 *
 * Carries the bank's own responseCode when there is one. That code is what separates a
 * wrong signature from an unknown account — the HTTP status is 200 for both, because
 * this API puts its verdict in the body.
 */
final class EcobankException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $responseCode = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
