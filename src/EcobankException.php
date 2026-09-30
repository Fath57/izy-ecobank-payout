<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/** Any refusal from the bank, or any configuration that would produce one. */
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
