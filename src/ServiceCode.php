<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * What a token opens.
 *
 * Chosen when requesting the token, not when spending it. This list comes from the
 * authentication service page of the developer portal; it is longer than what a payout
 * integration uses. Only DOMESTIC, INTERBANK and ACCOUNT_TRANSFER are accepted by the
 * transaction-status endpoint.
 */
final class ServiceCode
{
    /** Pool account to an Ecobank account in the same country. What a payout uses. */
    public const DOMESTIC = 'DOMESTIC';

    /** Ecobank account to another bank, same country. */
    public const INTERBANK = 'INTERBANK';

    /** Between accounts belonging to the same partner. */
    public const ACCOUNT_TRANSFER = 'ACCOUNT_TRANSFER';

    public const BILLPAYMENT = 'BILLPAYMENT';

    public const TOKEN = 'TOKEN';

    public const DIRECTDEBIT = 'DIRECTDEBIT';

    public const REMITTANCE = 'REMITTANCE';
}
