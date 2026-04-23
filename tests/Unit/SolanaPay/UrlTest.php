<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\SolanaPay;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\SolanaPayException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\SolanaPay\TransactionRequest;
use SolanaPhpSdk\SolanaPay\TransferRequest;
use SolanaPhpSdk\SolanaPay\Url;

/**
 * Solana Pay URL encoding/decoding tests.
 *
 * The headline test, testEncodeMatchesOfficialSdk, locks in byte-identical
 * output with the @solana/pay reference implementation across 10 cases
 * covering amount formatting, special character encoding, multi-reference
 * ordering, and both flavors of conditionally-encoded transaction request.
 */
final class UrlTest extends TestCase
{
    private const RECIPIENT = 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN';
    private const USDC     = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    private const REF1     = '82ZJ7nbGpixjeDCmEhUcmwXYfvurzAgGdtSMuHnUgyny';
    private const REF2     = '11111111111111111111111111111112';

    // ----- Encoding -------------------------------------------------------

    public function testEncodeMatchesOfficialSdk(): void
    {
        $fx = require __DIR__ . '/fixtures_solana_pay.php';

        // 1. Recipient only.
        $this->assertSame(
            $fx['transfer_recipient_only']['url'],
            Url::encodeTransfer(new TransferRequest(new PublicKey(self::RECIPIENT)))
        );

        // 2. SOL with amount 1.5.
        $this->assertSame(
            $fx['transfer_sol_amount_1_5']['url'],
            Url::encodeTransfer(new TransferRequest(new PublicKey(self::RECIPIENT), '1.5'))
        );

        // 3. Integer amount.
        $this->assertSame(
            $fx['transfer_sol_integer_amount']['url'],
            Url::encodeTransfer(new TransferRequest(new PublicKey(self::RECIPIENT), '1'))
        );

        // 4. Sub-unit amount.
        $this->assertSame(
            $fx['transfer_sol_sub_unit']['url'],
            Url::encodeTransfer(new TransferRequest(new PublicKey(self::RECIPIENT), '0.01'))
        );

        // 5. Full USDC payment with all fields.
        $full = $fx['transfer_full_usdc'];
        $request = new TransferRequest(
            new PublicKey($full['recipient']),
            $full['amount'],
            new PublicKey($full['splToken']),
            array_map(fn($r) => new PublicKey($r), $full['references']),
            $full['label'],
            $full['message'],
            $full['memo']
        );
        $this->assertSame($full['url'], Url::encodeTransfer($request));

        // 6. Multiple references preserve order.
        $multi = $fx['transfer_multiple_references'];
        $this->assertSame(
            $multi['url'],
            Url::encodeTransfer(new TransferRequest(
                new PublicKey($multi['recipient']),
                null, null,
                array_map(fn($r) => new PublicKey($r), $multi['references'])
            ))
        );

        // 7. Label with UTF-8 + ampersand.
        $label = $fx['transfer_label_special_chars'];
        $this->assertSame(
            $label['url'],
            Url::encodeTransfer(new TransferRequest(
                new PublicKey($label['recipient']),
                null, null, [],
                $label['label']
            ))
        );

        // 8. Message with spaces and '!'.
        $msg = $fx['transfer_message_spaces_and_bang'];
        $this->assertSame(
            $msg['url'],
            Url::encodeTransfer(new TransferRequest(
                new PublicKey($msg['recipient']),
                null, null, [],
                null, $msg['message']
            ))
        );

        // 9. Transaction request without query string (raw embed).
        $this->assertSame(
            $fx['transaction_no_query']['url'],
            Url::encodeTransaction(new TransactionRequest($fx['transaction_no_query']['link']))
        );

        // 10. Transaction request WITH query string (entire URL encoded).
        $this->assertSame(
            $fx['transaction_with_query']['url'],
            Url::encodeTransaction(new TransactionRequest($fx['transaction_with_query']['link']))
        );
    }

    public function testSpaceEncodesAsPlusNotPercent20(): void
    {
        // The Solana Pay spec / @solana/pay SDK uses application/x-www-form-urlencoded
        // style where space is '+'. PHP's rawurlencode() uses '%20' and is WRONG here.
        $url = Url::encodeTransfer(new TransferRequest(
            new PublicKey(self::RECIPIENT), null, null, [], 'hello world'
        ));
        $this->assertStringContainsString('label=hello+world', $url);
        $this->assertStringNotContainsString('hello%20world', $url);
    }

    // ----- Parsing --------------------------------------------------------

    public function testParseRoundTripAllVectors(): void
    {
        $fx = require __DIR__ . '/fixtures_solana_pay.php';

        foreach ($fx as $name => $vec) {
            $parsed = Url::parse($vec['url']);

            if (isset($vec['link'])) {
                // Transaction request
                $this->assertInstanceOf(TransactionRequest::class, $parsed, $name);
                $this->assertSame($vec['link'], $parsed->link, $name);
            } else {
                // Transfer request
                $this->assertInstanceOf(TransferRequest::class, $parsed, $name);
                $this->assertSame($vec['recipient'], $parsed->recipient->toBase58(), "{$name}: recipient");
                if (isset($vec['amount'])) {
                    $this->assertSame($vec['amount'], $parsed->amount, "{$name}: amount");
                }
                if (isset($vec['splToken'])) {
                    $this->assertSame($vec['splToken'], $parsed->splToken->toBase58(), "{$name}: splToken");
                }
                if (isset($vec['references'])) {
                    $this->assertCount(count($vec['references']), $parsed->references, "{$name}: ref count");
                    foreach ($vec['references'] as $i => $expected) {
                        $this->assertSame($expected, $parsed->references[$i]->toBase58(), "{$name}: ref[{$i}]");
                    }
                }
                if (isset($vec['label'])) {
                    $this->assertSame($vec['label'], $parsed->label, "{$name}: label");
                }
                if (isset($vec['message'])) {
                    $this->assertSame($vec['message'], $parsed->message, "{$name}: message");
                }
                if (isset($vec['memo'])) {
                    $this->assertSame($vec['memo'], $parsed->memo, "{$name}: memo");
                }
            }
        }
    }

    public function testParseRejectsWrongScheme(): void
    {
        $this->expectException(SolanaPayException::class);
        $this->expectExceptionMessage("Not a Solana Pay URL");
        Url::parse('https://example.com');
    }

    public function testParseRejectsEmptyPayload(): void
    {
        $this->expectException(SolanaPayException::class);
        Url::parse('solana:');
    }

    public function testParseRejectsInvalidRecipient(): void
    {
        $this->expectException(SolanaPayException::class);
        $this->expectExceptionMessage('Invalid recipient');
        Url::parse('solana:not-a-valid-pubkey?amount=1');
    }

    public function testParseIgnoresUnknownQueryParamsForForwardCompat(): void
    {
        // The spec allows future fields — our parser should tolerate them.
        $url = 'solana:' . self::RECIPIENT . '?amount=1&future-field=whatever';
        $req = Url::parse($url);
        $this->assertInstanceOf(TransferRequest::class, $req);
        $this->assertSame('1', $req->amount);
    }

    public function testParseRejectsInvalidAmount(): void
    {
        $this->expectException(SolanaPayException::class);
        Url::parse('solana:' . self::RECIPIENT . '?amount=.5'); // missing leading zero
    }

    public function testParseRejectsScientificNotationAmount(): void
    {
        $this->expectException(SolanaPayException::class);
        Url::parse('solana:' . self::RECIPIENT . '?amount=1e3');
    }

    public function testCaseInsensitiveScheme(): void
    {
        // Some scanners normalize the scheme. Parser accepts "SOLANA:" too.
        $req = Url::parse('SOLANA:' . self::RECIPIENT);
        $this->assertInstanceOf(TransferRequest::class, $req);
    }

    // ----- Amount validation ---------------------------------------------

    public function testAmountAcceptsValidValues(): void
    {
        // None of these should throw.
        foreach (['0', '1', '10', '0.5', '1.5', '0.000000001', '12345.6789'] as $v) {
            TransferRequest::validateAmount($v);
            $this->addToAssertionCount(1);
        }
    }

    public function testAmountRejectsInvalidFormats(): void
    {
        foreach (['', ' ', '-1', '.5', '1.', '01', '1e3', '1.5.6', 'abc', '1,5', 'NaN', 'Infinity'] as $v) {
            try {
                TransferRequest::validateAmount($v);
                $this->fail("Expected amount validation to reject: '{$v}'");
            } catch (SolanaPayException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAmountDecimalCountEnforcement(): void
    {
        // USDC has 6 decimals. 0.000001 (6 decimals) is valid; 0.0000001 (7) is not.
        TransferRequest::validateAmountDecimals('0.000001', 6); // ok
        TransferRequest::validateAmountDecimals('1', 6);        // integer always ok
        TransferRequest::validateAmountDecimals('1.5', 9);      // fewer decimals than max

        $this->expectException(SolanaPayException::class);
        TransferRequest::validateAmountDecimals('0.0000001', 6);
    }

    // ----- Transaction request -------------------------------------------

    public function testTransactionRequestRequiresHttps(): void
    {
        $this->expectException(SolanaPayException::class);
        new TransactionRequest('http://example.com/solana-pay');
    }

    public function testTransactionRequestRejectsRelativeUrl(): void
    {
        $this->expectException(SolanaPayException::class);
        new TransactionRequest('/solana-pay');
    }

    // ----- Builder --------------------------------------------------------

    public function testBuilderConstructsEquivalentRequest(): void
    {
        $recipient = new PublicKey(self::RECIPIENT);
        $mint = new PublicKey(self::USDC);
        $ref = new PublicKey(self::REF1);

        $built = TransferRequest::builder($recipient)
            ->amount('10')
            ->splToken($mint)
            ->addReference($ref)
            ->label('Store')
            ->memo('order:1')
            ->build();

        $this->assertTrue($built->recipient->equals($recipient));
        $this->assertSame('10', $built->amount);
        $this->assertTrue($built->splToken->equals($mint));
        $this->assertCount(1, $built->references);
        $this->assertTrue($built->references[0]->equals($ref));
        $this->assertSame('Store', $built->label);
        $this->assertSame('order:1', $built->memo);
    }

    // ----- Round-trip -----------------------------------------------------

    public function testEncodeThenParseYieldsEquivalentRequest(): void
    {
        $original = TransferRequest::builder(new PublicKey(self::RECIPIENT))
            ->amount('42.5')
            ->splToken(new PublicKey(self::USDC))
            ->addReference(new PublicKey(self::REF1))
            ->addReference(new PublicKey(self::REF2))
            ->label('My Store')
            ->message('Thanks for your purchase! €20 off next time')
            ->memo('order#1234')
            ->build();

        $url = Url::encodeTransfer($original);
        $reparsed = Url::parse($url);

        $this->assertInstanceOf(TransferRequest::class, $reparsed);
        $this->assertTrue($original->recipient->equals($reparsed->recipient));
        $this->assertSame($original->amount, $reparsed->amount);
        $this->assertTrue($original->splToken->equals($reparsed->splToken));
        $this->assertCount(2, $reparsed->references);
        $this->assertTrue($original->references[0]->equals($reparsed->references[0]));
        $this->assertTrue($original->references[1]->equals($reparsed->references[1]));
        $this->assertSame($original->label, $reparsed->label);
        $this->assertSame($original->message, $reparsed->message);
        $this->assertSame($original->memo, $reparsed->memo);
    }
}
