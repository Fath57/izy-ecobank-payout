<?php

declare(strict_types=1);

namespace Izy\EcobankPayout\Tests;

use Izy\EcobankPayout\Signature;
use PHPUnit\Framework\TestCase;

/**
 * The signature, checked against vectors/signature.json.
 *
 * The vectors are the contract a port has to meet. They are deliberately a data file
 * rather than assertions in code: an implementation in any language can load the same
 * file and check itself against the same expected values, which is the only way to know
 * a port is right — the sandbox accepts wrong signatures.
 */
final class SignatureTest extends TestCase
{
    /** @return array<string, mixed> */
    private function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../vectors/signature.json'), true);
    }

    public function test_every_vector_is_reproduced(): void
    {
        $vectors = $this->vectors();
        $signature = new Signature($vectors['secretKey']);

        foreach ($vectors['cases'] as $case) {
            if (! isset($case['expected'])) {
                continue;
            }

            $actual = isset($case['requestToken'])
                ? $signature->secureHash($case['header'], $case['requestToken'], $case['fields'])
                : $signature->requestToken($case['header']);

            $this->assertSame($case['expected'], $actual, $case['name']);
        }
    }

    /**
     * The trap the vectors exist to catch: hashing in the document's order.
     */
    public function test_the_document_order_produces_a_different_digest(): void
    {
        $case = $this->caseNamed('TRAP — hashing in document order is wrong');
        $vectors = $this->vectors();

        $this->assertSame(
            $case['wrongValue'],
            hash('sha512', $case['wrongConcatenation']),
            'The recorded wrong value no longer matches its own concatenation.'
        );

        $this->assertNotSame(
            $case['wrongValue'],
            (new Signature($vectors['secretKey']))->requestToken([
                'affiliateCode' => 'EGH',
                'clientId' => 'CL001',
                'sourceCode' => 'CORP_CIB_MOBILE',
                'requestId' => 'IZYABCDEFGH1234',
                'ipAddress' => '192.168.1.1',
                'requestType' => 'DOMESTIC_TRANSFER',
            ]),
        );
    }

    /**
     * The amount is hashed as text, and that text must be the JSON body's.
     */
    public function test_the_amount_renders_as_the_json_body_renders_it(): void
    {
        foreach ($this->caseNamed('amountString rendering')['samples'] as $sample) {
            $this->assertSame($sample['expected'], Signature::amountString($sample['amount']));

            $this->assertSame(
                trim(json_encode($sample['amount']), '"'),
                Signature::amountString($sample['amount']),
                sprintf('Rendering of %s diverges between the JSON body and the signature.', $sample['amount'])
            );
        }
    }

    /** The secret closes the string: two secrets cannot yield the same digest. */
    public function test_the_secret_key_closes_the_string(): void
    {
        $header = ['clientId' => 'CL001', 'affiliateCode' => 'EGH', 'sourceCode' => 'S',
            'requestId' => 'R', 'requestType' => 'T', 'ipAddress' => '1.1.1.1'];

        $this->assertNotSame(
            (new Signature('one'))->requestToken($header),
            (new Signature('another'))->requestToken($header),
        );
    }

    /** @return array<string, mixed> */
    private function caseNamed(string $name): array
    {
        foreach ($this->vectors()['cases'] as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }

        $this->fail("No vector named « {$name} ».");
    }
}
