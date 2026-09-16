<?php

declare(strict_types=1);

namespace Izy\EcobankPayout\Tests;

use Izy\EcobankPayout\Configuration;
use Izy\EcobankPayout\Payload;
use Izy\EcobankPayout\Signature;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the three payloads, and the rules a port must not lose.
 */
final class PayloadTest extends TestCase
{
    private function payload(): Payload
    {
        return new Payload(new Configuration(
            baseUrl: 'https://gateway.test',
            subscriptionKey: 'subscription',
            clientId: 'CL001',
            affiliateCode: 'EGH',
            sourceCode: 'CORP_CIB_MOBILE',
            publicKey: 'corp_public_sample',
            secretKey: 'test-secret-key-not-a-real-one',
            ipAddress: '192.168.1.1',
            currency: 'GHS',
        ));
    }

    public function test_the_header_carries_the_partner_identity_and_a_request_token(): void
    {
        $header = $this->payload()->header('DOMESTIC_TRANSFER', 'IZYABCDEFGH1234');

        $this->assertSame('CL001', $header['clientId']);
        $this->assertSame('EGH', $header['affiliateCode']);
        $this->assertSame('DOMESTIC_TRANSFER', $header['requestType']);
        $this->assertSame(128, strlen($header['requestToken']));
    }

    /**
     * A domestic transfer says where the money goes and never where it comes from.
     *
     * The pool account is attached to the clientId. A port that looks for a source
     * account field is looking at the previous API, or at Account Transfer.
     */
    public function test_a_domestic_transfer_names_no_source_account(): void
    {
        $body = $this->payload()->domesticTransfer('1441002006858', 10.0, 'Payout 42');

        $this->assertSame('1441002006858', $body['receiverAccountNo']);
        $this->assertSame('GHS', $body['currency']);
        $this->assertArrayNotHasKey('sourceAccountNo', $body);
    }

    /**
     * The two signatures differ, and the request token is part of the second.
     */
    public function test_the_two_signatures_differ(): void
    {
        $body = $this->payload()->domesticTransfer('1441002006858', 10.0, 'Payout 42');

        $this->assertSame(128, strlen($body['secureHash']));
        $this->assertNotSame($body['headerRequest']['requestToken'], $body['secureHash']);
    }

    /**
     * The amount in the body and the amount in the signature are the same text.
     *
     * This is the check a port should copy first: it catches the float-rendering
     * mismatch that produces a well-formed, refused digest.
     */
    public function test_the_signed_amount_matches_the_body(): void
    {
        $body = $this->payload()->domesticTransfer('1441002006858', 10.0, 'Payout 42');

        $encoded = json_decode(json_encode($body), true);

        $this->assertSame(
            Signature::amountString($body['amount']),
            trim(json_encode($encoded['amount']), '"'),
        );
    }

    /** Reusing a requestId is what stops a lost answer becoming a double payment. */
    public function test_a_request_id_can_be_reused_on_a_retry(): void
    {
        $first = $this->payload()->domesticTransfer('1441002006858', 10.0, 'Payout 42', 'IZYRETRY0000001');
        $second = $this->payload()->domesticTransfer('1441002006858', 10.0, 'Payout 42', 'IZYRETRY0000001');

        $this->assertSame('IZYRETRY0000001', $first['headerRequest']['requestId']);
        $this->assertSame($first['secureHash'], $second['secureHash'], 'Same order, same signature.');
    }

    public function test_a_minted_request_id_fits_the_field(): void
    {
        $id = Payload::newRequestId();

        $this->assertSame(15, strlen($id));
        $this->assertMatchesRegularExpression('/^IZY[A-Z0-9]+$/', $id);
    }

    /** The token request carries the publicKey — the secret never travels. */
    public function test_the_token_request_sends_the_public_key_only(): void
    {
        $body = $this->payload()->token();

        $this->assertSame('corp_public_sample', $body['publicKey']);
        $this->assertSame('DOMESTIC', $body['serviceCode']);
        $this->assertStringNotContainsString('test-secret-key', json_encode($body));
    }
}
