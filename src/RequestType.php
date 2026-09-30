<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/** The requestType each endpoint expects. */
final class RequestType
{
    public const GET_API_TOKEN = 'GET_API_TOKEN';

    public const DOMESTIC_TRANSFER = 'DOMESTIC_TRANSFER';

    public const TRANSACTION_STATUS = 'TRANSACTION_STATUS';
}
