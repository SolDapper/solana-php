<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Rpc;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Rpc\Commitment;
use SolanaPhpSdk\Rpc\ConfirmationOptions;
use SolanaPhpSdk\Rpc\ConfirmationResult;
use SolanaPhpSdk\Rpc\RpcClient;
use SolanaPhpSdk\Rpc\TransactionConfirmer;

/**
 * Unit tests for TransactionConfirmer.
 *
 * The confirmer's logic is heavily time-based, so the tests inject a fake
 * clock and a no-op sleeper. The fake clock advances by the requested
 * sleep duration each time sleep() is called - this lets us simulate
 * "wait 30 seconds" in zero real wall-clock time while still exercising
 * the deadline / timeout logic.
 */
final class TransactionConfirmerTest extends TestCase
{
    private const SIGNATURE = '4VCmDEKgMpsLpTqpKjMwHvVXzJqLWrQczeYTYW8c8eGQ8gsxnoYTGpZ7v8VfphLvrbTGB6tzZ4MdyTpRrCdPRZJF';

    private array $fakeNow = [1_700_000_000];
    private array $sleepLog = [];

    private function newRpc(): array
    {
        $mock = new MockHttpClient();
        return [new RpcClient('https://example.test/rpc', $mock), $mock];
    }

    private function newConfirmer(RpcClient $rpc): TransactionConfirmer
    {
        $this->fakeNow = [1_700_000_000];
        $this->sleepLog = [];
        $now =& $this->fakeNow;
        $log =& $this->sleepLog;

        $timeProvider = function () use (&$now): int {
            return $now[0];
        };
        $sleeper = function (int $seconds) use (&$now, &$log): void {
            $log[] = $seconds;
            $now[0] += $seconds;
        };

        return new TransactionConfirmer($rpc, $timeProvider, $sleeper);
    }

    // ----- Happy paths ---------------------------------------------------

    public function testReturnsConfirmedOnFirstSuccessfulPoll(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => 10, 'confirmationStatus' => 'confirmed', 'err' => null],
            ],
        ]);

        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE);

        $this->assertSame(ConfirmationResult::OUTCOME_CONFIRMED, $result->outcome);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(self::SIGNATURE, $result->signature);
        $this->assertSame('confirmed', $result->confirmationStatus);
        $this->assertSame(100, $result->slot);
        $this->assertNull($result->error);
        $this->assertSame(1, $result->pollCount);
    }

    public function testReturnsFinalizedWhenRequestingFinalized(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => null, 'confirmationStatus' => 'finalized', 'err' => null],
            ],
        ]);

        $result = $this->newConfirmer($rpc)
            ->awaitConfirmation(self::SIGNATURE, ConfirmationOptions::finalized());

        $this->assertSame(ConfirmationResult::OUTCOME_FINALIZED, $result->outcome);
        $this->assertTrue($result->isSuccess());
    }

    public function testFinalizedSatisfiesConfirmedRequest(): void
    {
        // If we asked for confirmed but the validator gives us finalized,
        // we should resolve - finalized is a stronger guarantee.
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => null, 'confirmationStatus' => 'finalized', 'err' => null],
            ],
        ]);

        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(ConfirmationResult::OUTCOME_FINALIZED, $result->outcome);
    }

    public function testProcessedDoesNotSatisfyConfirmedRequest(): void
    {
        [$rpc, $mock] = $this->newRpc();
        // First poll: processed (insufficient).
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => 0, 'confirmationStatus' => 'processed', 'err' => null],
            ],
        ]);
        // Second poll: confirmed (sufficient).
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => 10, 'confirmationStatus' => 'confirmed', 'err' => null],
            ],
        ]);

        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE);

        $this->assertSame(ConfirmationResult::OUTCOME_CONFIRMED, $result->outcome);
        $this->assertSame(2, $result->pollCount);
    }

    // ----- Failure path --------------------------------------------------

    public function testTransactionWithErrorReturnsFailedOutcome(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                [
                    'slot' => 100,
                    'confirmations' => 10,
                    'confirmationStatus' => 'confirmed',
                    'err' => ['InstructionError' => [0, ['Custom' => 1]]],
                ],
            ],
        ]);

        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE);

        $this->assertSame(ConfirmationResult::OUTCOME_FAILED, $result->outcome);
        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->error);
        $this->assertSame(['InstructionError' => [0, ['Custom' => 1]]], $result->error);
        $this->assertSame(100, $result->slot);
    }

    // ----- Timeout path --------------------------------------------------

    public function testTimeoutAfterDeadline(): void
    {
        [$rpc, $mock] = $this->newRpc();
        // Default response = "always tx not seen".
        $mock->setDefault(['result' => ['value' => [null]]]);

        $opts = new ConfirmationOptions(Commitment::CONFIRMED, 10);
        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE, $opts);

        $this->assertSame(ConfirmationResult::OUTCOME_TIMEOUT, $result->outcome);
        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->confirmationStatus);
        $this->assertNull($result->slot);
        $this->assertGreaterThanOrEqual(10, $result->elapsedSeconds);
    }

    // ----- Expiry path ---------------------------------------------------

    public function testExpiryDetectedWhenChainPassesLastValidHeight(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->setDefault(['result' => ['value' => [null]]]); // tx never seen
        // First getBlockHeight under threshold, second over.
        $mock->on('getBlockHeight')->respond(99);
        $mock->on('getBlockHeight')->respond(101);

        $opts = (new ConfirmationOptions(Commitment::CONFIRMED, 60))->withBlockhashExpiry(100);
        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE, $opts);

        $this->assertSame(ConfirmationResult::OUTCOME_EXPIRED, $result->outcome);
        $this->assertFalse($result->isSuccess());
    }

    public function testExpiryNotDetectedWhenChainStillUnderHeight(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->setDefault(['result' => ['value' => [null]]]); // tx never seen, getBlockHeight returns null too
        // Enqueue a few getBlockHeight responses under the threshold; the
        // setDefault catches the rest. With short timeout we should hit
        // TIMEOUT before any expiry trigger.
        for ($i = 0; $i < 10; $i++) {
            $mock->on('getBlockHeight')->respond(50);
        }

        $opts = (new ConfirmationOptions(Commitment::CONFIRMED, 5))->withBlockhashExpiry(100);
        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE, $opts);

        $this->assertSame(ConfirmationResult::OUTCOME_TIMEOUT, $result->outcome);
    }

    // ----- Rebroadcast ---------------------------------------------------

    public function testRebroadcastSubmitsWireBytesPeriodically(): void
    {
        [$rpc, $mock] = $this->newRpc();
        // Several null statuses then a confirmed one.
        $mock->on('getSignatureStatuses')->respond(['value' => [null]]);
        $mock->on('getSignatureStatuses')->respond(['value' => [null]]);
        $mock->on('getSignatureStatuses')->respond(['value' => [null]]);
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => 10, 'confirmationStatus' => 'confirmed', 'err' => null],
            ],
        ]);
        // Allow several rebroadcasts.
        for ($i = 0; $i < 5; $i++) {
            $mock->on('sendTransaction')->respond('rebroadcast-sig');
        }

        $opts = (new ConfirmationOptions(Commitment::CONFIRMED, 60))
            ->withRebroadcast("\x01\x02\x03\x04\x05", 1);
        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE, $opts);

        $this->assertSame(ConfirmationResult::OUTCOME_CONFIRMED, $result->outcome);
        $sendCalls = array_filter($mock->requests, fn($r) => $r['method'] === 'sendTransaction');
        $this->assertGreaterThanOrEqual(1, count($sendCalls),
            'Should have rebroadcast at least once during the wait');
    }

    public function testRebroadcastFailureIsTolerated(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getSignatureStatuses')->respond(['value' => [null]]);
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => 10, 'confirmationStatus' => 'confirmed', 'err' => null],
            ],
        ]);
        for ($i = 0; $i < 5; $i++) {
            $mock->on('sendTransaction')->respondError('AlreadyProcessed', -32002);
        }

        $opts = (new ConfirmationOptions(Commitment::CONFIRMED, 60))
            ->withRebroadcast("\x01\x02", 1);
        $result = $this->newConfirmer($rpc)->awaitConfirmation(self::SIGNATURE, $opts);

        $this->assertSame(ConfirmationResult::OUTCOME_CONFIRMED, $result->outcome,
            'Rebroadcast errors should not prevent confirmation');
    }

    // ----- Multi-signature flow ------------------------------------------

    public function testAwaitMultipleResolvesEachSignatureSeparately(): void
    {
        [$rpc, $mock] = $this->newRpc();
        // First poll: sig1 confirmed, sig2 pending.
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => 10, 'confirmationStatus' => 'confirmed', 'err' => null],
                null,
            ],
        ]);
        // Second poll: only sig2 still pending - mock returns one entry.
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 102, 'confirmations' => 5, 'confirmationStatus' => 'confirmed', 'err' => null],
            ],
        ]);

        $results = $this->newConfirmer($rpc)->awaitMultiple(['sig1', 'sig2']);

        $this->assertCount(2, $results);
        $this->assertSame(ConfirmationResult::OUTCOME_CONFIRMED, $results['sig1']->outcome);
        $this->assertSame(ConfirmationResult::OUTCOME_CONFIRMED, $results['sig2']->outcome);
    }

    public function testAwaitMultipleHandlesEmpty(): void
    {
        [$rpc, ] = $this->newRpc();
        $this->assertSame([], $this->newConfirmer($rpc)->awaitMultiple([]));
    }

    public function testAwaitMultipleDeduplicatesInputSignatures(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getSignatureStatuses')->respond([
            'value' => [
                ['slot' => 100, 'confirmations' => 10, 'confirmationStatus' => 'confirmed', 'err' => null],
            ],
        ]);

        $results = $this->newConfirmer($rpc)->awaitMultiple(['sig1', 'sig1', 'sig1']);
        $this->assertCount(1, $results);
    }

    // ----- ConfirmationOptions validation --------------------------------

    public function testInvalidCommitmentRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConfirmationOptions('not-a-commitment-level');
    }

    public function testZeroTimeoutRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConfirmationOptions(Commitment::CONFIRMED, 0);
    }

    public function testMaxIntervalLessThanInitialRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConfirmationOptions(Commitment::CONFIRMED, 60, 5, 3);
    }

    public function testFactoryMethodsProduceExpectedCommitments(): void
    {
        $this->assertSame(Commitment::CONFIRMED, ConfirmationOptions::confirmed()->commitment);
        $this->assertSame(Commitment::FINALIZED, ConfirmationOptions::finalized()->commitment);
        $this->assertSame(Commitment::PROCESSED, ConfirmationOptions::processed()->commitment);
    }

    public function testWithRebroadcastReturnsCopyNotMutation(): void
    {
        $original = ConfirmationOptions::confirmed();
        $modified = $original->withRebroadcast("\x01\x02", 3);
        $this->assertNull($original->rebroadcastWireBytes);
        $this->assertSame("\x01\x02", $modified->rebroadcastWireBytes);
        $this->assertSame(3, $modified->rebroadcastEvery);
    }

    public function testWithBlockhashExpiryReturnsCopyNotMutation(): void
    {
        $original = ConfirmationOptions::confirmed();
        $modified = $original->withBlockhashExpiry(12345);
        $this->assertNull($original->lastValidBlockHeight);
        $this->assertSame(12345, $modified->lastValidBlockHeight);
    }
}
