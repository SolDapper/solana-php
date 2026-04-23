<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Programs;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\AssociatedTokenProgram;
use SolanaPhpSdk\Programs\ComputeBudgetProgram;
use SolanaPhpSdk\Programs\MemoProgram;
use SolanaPhpSdk\Programs\TokenProgram;
use SolanaPhpSdk\Transaction\Transaction;

/**
 * End-to-end test: build and sign a realistic payment transaction using
 * every building block the SDK provides.
 *
 * This mimics the shape of a real USDC payment from a customer to a
 * merchant in an ecommerce flow:
 *
 *   1. setComputeUnitLimit       — optimize CU reservation (lower fee)
 *   2. setComputeUnitPrice       — priority fee for fast confirmation
 *   3. createIdempotent (merchant ATA) — safe ATA create for receiver
 *   4. transferChecked           — the actual USDC transfer, with mint+decimals check
 *   5. memo                      — order ID for correlation with ecom system
 *
 * If this compiles, signs, serializes, and round-trips verify() correctly,
 * the entire SDK stack is working together as intended.
 */
final class PaymentTransactionIntegrationTest extends TestCase
{
    public function testFullPaymentTransactionRoundTrip(): void
    {
        $customer = Keypair::fromSeed(str_repeat("\x11", 32));
        $merchant = Keypair::fromSeed(str_repeat("\x22", 32))->getPublicKey();
        $usdc = new PublicKey('EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v');
        $blockhash = str_repeat("\x33", 32);

        // Derive ATAs for both parties.
        [$customerAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
            $customer->getPublicKey(), $usdc
        );
        [$merchantAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
            $merchant, $usdc
        );

        // Build the payment transaction.
        $tx = Transaction::new(
            [
                ComputeBudgetProgram::setComputeUnitLimit(80_000),
                ComputeBudgetProgram::setComputeUnitPrice(10_000),
                AssociatedTokenProgram::createIdempotent(
                    $customer->getPublicKey(), // payer (who pays ATA rent if created)
                    $merchantAta,
                    $merchant,
                    $usdc
                ),
                TokenProgram::transferChecked(
                    $customerAta,
                    $usdc,
                    $merchantAta,
                    $customer->getPublicKey(),
                    10_000_000, // 10 USDC
                    6           // USDC decimals
                ),
                MemoProgram::create('order_ref:OC-2025-00042'),
            ],
            $customer->getPublicKey(),
            $blockhash
        );

        // Expected account structure:
        // Category 1 (writable signer, idx 0): customer (fee payer + signs the transferChecked)
        // Category 3 (writable non-signer): customerAta, merchantAta
        // Category 4 (readonly non-signer): merchant, usdc, System, Token, ATA program, Memo program, ComputeBudget program
        $msg = $tx->message;
        $this->assertSame(1, $msg->numRequiredSignatures, 'Only the customer signs');
        $this->assertTrue($msg->accountKeys[0]->equals($customer->getPublicKey()));

        // Sign and verify
        $tx->sign($customer);
        $this->assertTrue($tx->verifySignatures());

        // Round-trip through serialization to make sure nothing silently breaks.
        $wire = $tx->serialize();
        $restored = Transaction::deserialize($wire);
        $this->assertSame(bin2hex($wire), bin2hex($restored->serialize()));
        $this->assertTrue($restored->verifySignatures());

        // Stay under the 1232-byte tx size limit.
        $this->assertLessThanOrEqual(
            1232,
            strlen($wire),
            'Typical payment transactions must fit in the MTU budget'
        );
    }

    public function testSplTokenPaymentFitsWellUnderMtu(): void
    {
        // Sanity check: even with maximal realistic metadata the tx fits.
        $customer = Keypair::generate();
        $merchantAta = Keypair::generate()->getPublicKey();
        $customerAta = Keypair::generate()->getPublicKey();
        $usdc = new PublicKey('EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v');
        $blockhash = str_repeat("\x00", 32);

        $tx = Transaction::new(
            [
                ComputeBudgetProgram::setComputeUnitLimit(80_000),
                ComputeBudgetProgram::setComputeUnitPrice('18446744073709551615'), // absurd
                TokenProgram::transferChecked(
                    $customerAta, $usdc, $merchantAta, $customer->getPublicKey(),
                    '1000000000000', 6
                ),
                MemoProgram::create(str_repeat('x', 200)), // ~200-byte memo
            ],
            $customer->getPublicKey(),
            $blockhash
        );
        $tx->sign($customer);

        $this->assertLessThan(1232, strlen($tx->serialize()));
    }
}
