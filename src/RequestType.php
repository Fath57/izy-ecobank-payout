<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * The requestType each endpoint expects.
 *
 * It is hashed into both signatures, so getting it wrong produces a refused digest
 * rather than a routing error — the failure looks like a credentials problem.
 *
 * The portal's "Request Type" page, which would list them all, is served empty (checked
 * 13 Sep 2026). These three are read off the operation pages themselves.
 */
final class RequestType
{
    public const GET_API_TOKEN = 'GET_API_TOKEN';

    public const DOMESTIC_TRANSFER = 'DOMESTIC_TRANSFER';

    public const TRANSACTION_STATUS = 'TRANSACTION_STATUS';
}
