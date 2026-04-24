<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Rpc;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\ComputeBudgetProgram;
use SolanaPhpSdk\Programs\SystemProgram;
use SolanaPhpSdk\Rpc\ComputeUnitEstimator;
use SolanaPhpSdk\Rpc\RpcClient;
use SolanaPhpSdk\Transaction\AddressLookupTableAccount;
use SolanaPhpSdk\Transaction\TransactionInstruction;

/**
 * Unit tests for {@see ComputeUnitEstimator}.
 *
 * The estimator's logic is relatively simple (build placeholder tx, call
 * simulateTransaction, parse result), but there are a number of edge cases
 * where failure modes matter: zero CU usage, simulation errors, u64 overflow,
 * invalid parameters. These tests pin down the expected behavior for each.
 */
final class ComputeUnitEstimatorTest extends TestCase
{
    private const BLOCKHASH = 'GHtXQBsoZHVnNFa9YevAzFr17DJjgHXk3ycTKD5xD3Zi';

    private Keypair $payer;
    private PublicKey $recipient;

    protected function setUp(): void
    {
        $this->payer = Keypair::fromSeed(str_repeat("\x11", 32));
        $this->recipient = Keypair::fromSeed(str_repeat("\x22", 32))->getPublicKey();
    }

    private function newRpc(): array
    {
        $mock = new MockHttpClient();
        return [new RpcClient('https://example.test/rpc', $mock), $mock];
    }

    // ----- Happy path ----------------------------------------------------

    public function testEstimateLegacyReturnsScaledLimit(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'context' => ['slot' => 100],
            'value' => [
                'unitsConsumed' => 450,
                'logs' => ['Program 11111111111111111111111111111111 invoke [1]'],
                'err' => null,
            ],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1000)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            1.1,
            1000
        );

        $this->assertSame(450, $estimate->unitsConsumed);
        // Observed 450, multiplier 1.1 -> ceil(495) = 495, but floor is 1000, so final is 1000.
        $this->assertSame(1000, $estimate->recommendedLimit);
        $this->assertSame(1.1, $estimate->multiplier);
        $this->assertTrue($estimate->simulationSucceeded);
        $this->assertSame(['Program 11111111111111111111111111111111 invoke [1]'], $estimate->simulationLogs);
    }

    public function testEstimateLegacyFloorOverridesLowMultiplication(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 100, 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            1.1,
            1000
        );

        // 100 * 1.1 = 110, but floor=1000, so recommended=1000
        $this->assertSame(100, $estimate->unitsConsumed);
        $this->assertSame(1000, $estimate->recommendedLimit);
    }

    public function testEstimateLegacyWithHigherMultiplier(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 80_000, 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            1.2,  // 20% headroom
            1000
        );

        // 80000 * 1.2 = 96000, well above floor
        $this->assertSame(80_000, $estimate->unitsConsumed);
        $this->assertSame(96_000, $estimate->recommendedLimit);
    }

    public function testEstimateCapsAtRuntimeMax(): void
    {
        [$rpc, $mock] = $this->newRpc();
        // Pathological case: simulator reports 1.3M CU, with 1.2x margin that'd be 1.56M
        // which exceeds the 1.4M runtime cap.
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 1_300_000, 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            1.2,
            1000
        );

        // Should be clamped to 1_400_000 even though 1.3M * 1.2 = 1.56M
        $this->assertSame(1_400_000, $estimate->recommendedLimit);
    }

    public function testEstimateRequestShapeIsCorrect(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 500, 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );

        // Verify simulate was called with the right options.
        $this->assertCount(1, $mock->requests);
        $req = $mock->requests[0];
        $this->assertSame('simulateTransaction', $req['method']);
        $opts = $req['params'][1];
        $this->assertTrue($opts['replaceRecentBlockhash'],
            'Must use replaceRecentBlockhash: true so caller does not need a fresh blockhash');
        $this->assertFalse($opts['sigVerify'],
            'Must use sigVerify: false so caller does not need real signatures');
    }

    public function testEstimateInjectsPlaceholderCuLimit(): void
    {
        // The estimator must inject a 1.4M CU placeholder so simulation
        // isn't constrained by a small limit. Verify this by inspecting the
        // transaction sent.
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 500, 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );

        $req = $mock->requests[0];
        // params[0] is the base64-encoded wire bytes of the transaction.
        $wireB64 = $req['params'][0];
        $wire = base64_decode($wireB64);

        // The ComputeBudgetProgram SetComputeUnitLimit ix discriminator is 0x02
        // followed by a u32 LE value. 1_400_000 = 0x00155CC0 -> LE bytes "C05C1500"
        $this->assertStringContainsString('02c05c1500', bin2hex($wire),
            'Wire bytes must contain the 1.4M CU placeholder setComputeUnitLimit ix');
    }

    // ----- Simulation-level failure (not RPC failure) -------------------

    public function testEstimateReportsSimulationErrorWithoutThrowing(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => [
                'unitsConsumed' => 200,
                'logs' => ['Program failed: insufficient funds'],
                'err' => ['InstructionError' => [0, ['Custom' => 1]]],
            ],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        // Simulation-level errors should be reported in the return value,
        // not thrown - the caller may want to see the logs for debugging.
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );

        $this->assertFalse($estimate->simulationSucceeded);
        $this->assertNotNull($estimate->simulationError);
        $this->assertSame(200, $estimate->unitsConsumed);
        $this->assertContains('Program failed: insufficient funds', $estimate->simulationLogs);
    }

    // ----- Missing or malformed unitsConsumed ---------------------------

    public function testMissingUnitsConsumedFallsBackToZero(): void
    {
        [$rpc, $mock] = $this->newRpc();
        // Some RPC providers omit unitsConsumed when simulation fails very early.
        $mock->on('simulateTransaction')->respond([
            'value' => [
                'logs' => ['Program failed before start'],
                'err' => 'BlockhashNotFound',
            ],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );

        $this->assertSame(0, $estimate->unitsConsumed);
        $this->assertFalse($estimate->simulationSucceeded);
        // Recommended limit is still valid - just the floor value.
        $this->assertSame(1000, $estimate->recommendedLimit);
    }

    public function testUnitsConsumedAsStringIsParsed(): void
    {
        // Some providers return u64 values as JSON strings to avoid precision loss.
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => '85000', 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );

        $this->assertSame(85_000, $estimate->unitsConsumed);
    }

    // ----- Parameter validation -----------------------------------------

    public function testMultiplierBelowOneRejected(): void
    {
        [$rpc, ] = $this->newRpc();
        $estimator = new ComputeUnitEstimator($rpc);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiplier must be >= 1.0');
        $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            0.9
        );
    }

    public function testNegativeFloorRejected(): void
    {
        [$rpc, ] = $this->newRpc();
        $estimator = new ComputeUnitEstimator($rpc);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Floor must be non-negative');
        $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            1.1,
            -100
        );
    }

    public function testExactlyOneMultiplierAllowed(): void
    {
        // 1.0 is the minimum valid multiplier (no headroom, but not invalid).
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 5000, 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            1.0,
            1000
        );

        // 5000 * 1.0 = 5000, above 1000 floor
        $this->assertSame(5000, $estimate->recommendedLimit);
    }

    // ----- V0 path -------------------------------------------------------

    public function testEstimateV0ReturnsExpectedResult(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 60_000, 'logs' => [], 'err' => null],
        ]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateV0(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1000)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            [],
            1.1
        );

        $this->assertSame(60_000, $estimate->unitsConsumed);
        $this->assertSame(66_000, $estimate->recommendedLimit); // 60k * 1.1
    }

    public function testEstimateV0WithAddressLookupTable(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respond([
            'value' => ['unitsConsumed' => 30_000, 'logs' => [], 'err' => null],
        ]);

        // ALT containing an extra recipient address.
        $altAddr = Keypair::fromSeed(str_repeat("\x30", 32))->getPublicKey();
        $extraRecipient = Keypair::fromSeed(str_repeat("\x31", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altAddr, [$extraRecipient]);

        $estimator = new ComputeUnitEstimator($rpc);
        $estimate = $estimator->estimateV0(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 100)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH,
            [$alt]
        );

        $this->assertSame(30_000, $estimate->unitsConsumed);
    }

    // ----- RPC-level failure --------------------------------------------

    public function testRpcErrorPropagates(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('simulateTransaction')->respondError('Internal error', -32603);

        $estimator = new ComputeUnitEstimator($rpc);

        $this->expectException(RpcException::class);
        $estimator->estimateLegacy(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->recipient, 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );
    }
}
