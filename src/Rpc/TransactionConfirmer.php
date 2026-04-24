<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\RpcException;

/**
 * Polls the chain until a transaction reaches the requested commitment
 * level, or terminates in another way (failure, blockhash expiry, timeout).
 *
 * The polling strategy follows the canonical web3.js pattern with three
 * production-grade additions:
 *
 *   1. Exponential-ish backoff (initialPollInterval -> maxPollInterval)
 *      so we don't hammer rate-limited free-tier RPCs.
 *
 *   2. Optional blockhash-expiry detection: by passing the
 *      `lastValidBlockHeight` from the original getLatestBlockhash call,
 *      the confirmer aborts with EXPIRED outcome the moment the chain
 *      passes that height, rather than waiting for the timeout.
 *
 *   3. Optional rebroadcast: validators sometimes drop transactions
 *      silently. Re-submitting the same wire bytes every few seconds
 *      during the wait is documented as best practice by Helius, Triton,
 *      and the Solana Cookbook.
 *
 * Typical use:
 *
 *   $sig = $rpc->sendTransaction($tx);
 *   $confirmer = new TransactionConfirmer($rpc);
 *   $result = $confirmer->awaitConfirmation($sig);
 *   if ($result->isSuccess()) {
 *       // mark the order paid
 *   }
 *
 * For high-value flows that need finalized commitment:
 *
 *   $result = $confirmer->awaitConfirmation($sig, ConfirmationOptions::finalized());
 *
 * Two-stage pattern (fast UX, audit-grade record later):
 *
 *   $confirmed = $confirmer->awaitConfirmation($sig);  // ~2s typical
 *   if ($confirmed->isSuccess()) {
 *       $this->markOrderPaid();   // ship to customer immediately
 *
 *       // Then in a background job:
 *       $finalized = $confirmer->awaitConfirmation(
 *           $sig, ConfirmationOptions::finalized()
 *       );
 *       $this->auditLog->record($sig, $finalized);
 *   }
 */
final class TransactionConfirmer
{
    private RpcClient $rpc;

    /** @var callable(): int Test-overridable wall clock. Returns Unix timestamp. */
    private $clock;

    /** @var callable(int): void Test-overridable sleep function. */
    private $sleeper;

    public function __construct(
        RpcClient $rpc,
        ?callable $clock = null,
        ?callable $sleeper = null
    ) {
        $this->rpc = $rpc;
        // Defaults use real wall clock and real sleep. Tests inject fakes
        // so they can simulate elapsed time without actually waiting.
        $this->clock = $clock ?? (static fn (): int => time());
        $this->sleeper = $sleeper ?? (static function (int $secs): void {
            if ($secs > 0) {
                sleep($secs);
            }
        });
    }

    /**
     * Wait for a single signature to confirm.
     *
     * Note that a transaction-level error (the tx ran but failed) does NOT
     * throw: it returns a result with outcome=OUTCOME_FAILED. Network
     * errors during getSignatureStatuses or getBlockHeight propagate as
     * RpcException only if every poll fails; transient errors are swallowed.
     *
     * @throws InvalidArgumentException If the signature is empty.
     */
    public function awaitConfirmation(
        string $signature,
        ?ConfirmationOptions $options = null
    ): ConfirmationResult {
        if ($signature === '') {
            throw new InvalidArgumentException('signature must be non-empty');
        }
        $options ??= ConfirmationOptions::confirmed();

        $start = ($this->clock)();
        $deadline = $start + $options->timeoutSeconds;
        $pollInterval = $options->initialPollInterval;
        $pollCount = 0;
        $rebroadcastCount = 0;
        $lastRebroadcast = $start;

        // Track the last status seen so we can populate slot/cstatus on
        // a TIMEOUT result if the chain saw the tx but didn't advance it
        // far enough by the deadline.
        $lastSeenStatus = null;

        while (($this->clock)() < $deadline) {
            $pollCount++;

            // 1) Check signature status.
            try {
                $statuses = $this->rpc->getSignatureStatuses([$signature]);
                $status = $statuses[0] ?? null;
            } catch (RpcException $e) {
                // Transient. Sleep and try again.
                $status = null;
            }

            if ($status !== null) {
                $lastSeenStatus = $status;
                $confirmationStatus = $status['confirmationStatus'] ?? null;
                $slot = isset($status['slot']) ? (int) $status['slot'] : null;
                $err = $status['err'] ?? null;

                if ($err !== null) {
                    // Transaction landed but execution errored. Terminal:
                    // re-broadcasting won't help, the chain has it and it's bad.
                    return $this->buildResult(
                        $signature, ConfirmationResult::OUTCOME_FAILED,
                        $confirmationStatus, $slot, $err,
                        $pollCount, $start, $rebroadcastCount
                    );
                }

                if ($this->meetsCommitment($confirmationStatus, $options->commitment)) {
                    // Reached or exceeded the requested commitment level.
                    $outcome = ($confirmationStatus === Commitment::FINALIZED)
                        ? ConfirmationResult::OUTCOME_FINALIZED
                        : ConfirmationResult::OUTCOME_CONFIRMED;
                    return $this->buildResult(
                        $signature, $outcome,
                        $confirmationStatus, $slot, null,
                        $pollCount, $start, $rebroadcastCount
                    );
                }
                // Otherwise: tx is known but at a lower commitment than requested.
                // Keep polling until it advances (or we hit blockhash expiry / timeout).
            }

            // 2) Check blockhash expiry, if configured.
            if ($options->lastValidBlockHeight !== null) {
                try {
                    $currentHeight = (int) $this->rpc->getBlockHeight();
                    if ($currentHeight > $options->lastValidBlockHeight) {
                        // Blockhash is past its valid window. The tx will
                        // never land. EXPIRED is terminal regardless of
                        // whether the chain currently knows about the tx.
                        $cstatus = $lastSeenStatus['confirmationStatus'] ?? null;
                        $slot = isset($lastSeenStatus['slot']) ? (int) $lastSeenStatus['slot'] : null;
                        return $this->buildResult(
                            $signature, ConfirmationResult::OUTCOME_EXPIRED,
                            $cstatus, $slot, null,
                            $pollCount, $start, $rebroadcastCount
                        );
                    }
                } catch (RpcException $e) {
                    // Don't fail the whole wait on a transient getBlockHeight
                    // error. We re-check on the next poll iteration.
                }
            }

            // 3) Optionally rebroadcast.
            if ($options->rebroadcastWireBytes !== null
                && (($this->clock)() - $lastRebroadcast) >= $options->rebroadcastEvery
            ) {
                try {
                    $this->rpc->sendRawTransaction(
                        $options->rebroadcastWireBytes,
                        ['skipPreflight' => true]
                    );
                    $rebroadcastCount++;
                    $lastRebroadcast = ($this->clock)();
                } catch (RpcException $e) {
                    // Rebroadcast errors are non-fatal: just means this
                    // attempt failed (often "AlreadyProcessed", which is
                    // actually a good sign). Keep polling.
                }
            }

            // 4) Sleep with backoff.
            ($this->sleeper)($pollInterval);
            $pollInterval = min($pollInterval * 2, $options->maxPollInterval);
        }

        // Loop exited because we hit the deadline. The transaction may still
        // land later, but we're giving up on this wait. One last status
        // check so the result reflects the freshest information we have.
        try {
            $finalStatuses = $this->rpc->getSignatureStatuses([$signature]);
            if (isset($finalStatuses[0]) && $finalStatuses[0] !== null) {
                $lastSeenStatus = $finalStatuses[0];
            }
        } catch (RpcException $e) {
            // Ignore - just leaves $lastSeenStatus as whatever we last saw.
        }

        $cstatus = $lastSeenStatus['confirmationStatus'] ?? null;
        $slot = isset($lastSeenStatus['slot']) ? (int) $lastSeenStatus['slot'] : null;
        return $this->buildResult(
            $signature, ConfirmationResult::OUTCOME_TIMEOUT,
            $cstatus, $slot, null,
            $pollCount, $start, $rebroadcastCount
        );
    }

    /**
     * Wait for multiple signatures concurrently. Polls all signatures in
     * a single getSignatureStatuses call per iteration (the RPC supports
     * batched queries), so the chain isn't queried N times per round.
     *
     * Duplicate signatures in the input array are deduplicated. Returns a
     * map keyed by signature so callers can correlate outcomes.
     *
     * @param array<int, string> $signatures
     * @return array<string, ConfirmationResult>
     */
    public function awaitMultiple(
        array $signatures,
        ?ConfirmationOptions $options = null
    ): array {
        if ($signatures === []) {
            return [];
        }
        foreach ($signatures as $i => $sig) {
            if (!is_string($sig) || $sig === '') {
                throw new InvalidArgumentException(
                    "Signature at index {$i} must be a non-empty string"
                );
            }
        }
        $options ??= ConfirmationOptions::confirmed();

        $start = ($this->clock)();
        $deadline = $start + $options->timeoutSeconds;
        $pollInterval = $options->initialPollInterval;
        $pollCount = 0;
        $rebroadcastCount = 0;
        $lastRebroadcast = $start;

        // Deduplicate. Use the signature as both key and value so we keep
        // insertion order and can iterate with O(1) removal.
        $pending = [];
        foreach ($signatures as $sig) {
            $pending[$sig] = $sig;
        }

        // Track last seen status per signature for timeout reporting.
        $lastSeenStatus = [];

        $results = [];

        while (count($pending) > 0 && ($this->clock)() < $deadline) {
            $pollCount++;
            $pendingList = array_values($pending);

            try {
                $statuses = $this->rpc->getSignatureStatuses($pendingList);
            } catch (RpcException $e) {
                $statuses = [];
            }

            $statusBySig = [];
            foreach ($pendingList as $i => $sig) {
                $statusBySig[$sig] = $statuses[$i] ?? null;
            }

            // Check blockhash expiry once per round (not per signature) so
            // we don't multiply RPC load.
            $blockhashExpired = false;
            if ($options->lastValidBlockHeight !== null) {
                try {
                    $currentHeight = (int) $this->rpc->getBlockHeight();
                    $blockhashExpired = ($currentHeight > $options->lastValidBlockHeight);
                } catch (RpcException $e) {
                    // Transient. Try again next round.
                }
            }

            foreach ($pendingList as $sig) {
                $status = $statusBySig[$sig];

                if ($status !== null) {
                    $lastSeenStatus[$sig] = $status;
                    $cstatus = $status['confirmationStatus'] ?? null;
                    $slot = isset($status['slot']) ? (int) $status['slot'] : null;
                    $err = $status['err'] ?? null;

                    if ($err !== null) {
                        $results[$sig] = $this->buildResult(
                            $sig, ConfirmationResult::OUTCOME_FAILED,
                            $cstatus, $slot, $err,
                            $pollCount, $start, $rebroadcastCount
                        );
                        unset($pending[$sig]);
                        continue;
                    }
                    if ($this->meetsCommitment($cstatus, $options->commitment)) {
                        $outcome = ($cstatus === Commitment::FINALIZED)
                            ? ConfirmationResult::OUTCOME_FINALIZED
                            : ConfirmationResult::OUTCOME_CONFIRMED;
                        $results[$sig] = $this->buildResult(
                            $sig, $outcome,
                            $cstatus, $slot, null,
                            $pollCount, $start, $rebroadcastCount
                        );
                        unset($pending[$sig]);
                        continue;
                    }
                }

                if ($blockhashExpired) {
                    $cstatus = $lastSeenStatus[$sig]['confirmationStatus'] ?? null;
                    $slot = isset($lastSeenStatus[$sig]['slot'])
                        ? (int) $lastSeenStatus[$sig]['slot']
                        : null;
                    $results[$sig] = $this->buildResult(
                        $sig, ConfirmationResult::OUTCOME_EXPIRED,
                        $cstatus, $slot, null,
                        $pollCount, $start, $rebroadcastCount
                    );
                    unset($pending[$sig]);
                }
            }

            if (count($pending) === 0) {
                break;
            }

            // Optional rebroadcast (a single wire shared across all sigs is
            // unusual: this only really makes sense when the caller is
            // waiting on one tx wire that produced one signature. We support
            // it for symmetry with awaitConfirmation().)
            if ($options->rebroadcastWireBytes !== null
                && (($this->clock)() - $lastRebroadcast) >= $options->rebroadcastEvery
            ) {
                try {
                    $this->rpc->sendRawTransaction(
                        $options->rebroadcastWireBytes,
                        ['skipPreflight' => true]
                    );
                    $rebroadcastCount++;
                    $lastRebroadcast = ($this->clock)();
                } catch (RpcException $e) {
                    // Non-fatal.
                }
            }

            ($this->sleeper)($pollInterval);
            $pollInterval = min($pollInterval * 2, $options->maxPollInterval);
        }

        // Anything still pending hit the timeout.
        foreach ($pending as $sig) {
            $cstatus = $lastSeenStatus[$sig]['confirmationStatus'] ?? null;
            $slot = isset($lastSeenStatus[$sig]['slot'])
                ? (int) $lastSeenStatus[$sig]['slot']
                : null;
            $results[$sig] = $this->buildResult(
                $sig, ConfirmationResult::OUTCOME_TIMEOUT,
                $cstatus, $slot, null,
                $pollCount, $start, $rebroadcastCount
            );
        }

        return $results;
    }

    /**
     * True if the given confirmationStatus from the chain meets or exceeds
     * the requested commitment level.
     *
     * Ordering: processed < confirmed < finalized.
     */
    private function meetsCommitment(?string $actual, string $requested): bool
    {
        if ($actual === null) {
            return false;
        }
        $rank = [
            Commitment::PROCESSED => 1,
            Commitment::CONFIRMED => 2,
            Commitment::FINALIZED => 3,
        ];
        $actualRank = $rank[$actual] ?? 0;
        $requestedRank = $rank[$requested] ?? 0;
        return $actualRank >= $requestedRank;
    }

    /**
     * @param mixed $error
     */
    private function buildResult(
        string $signature,
        string $outcome,
        ?string $confirmationStatus,
        ?int $slot,
        $error,
        int $pollCount,
        int $startEpoch,
        int $rebroadcastCount
    ): ConfirmationResult {
        $elapsed = ($this->clock)() - $startEpoch;
        return new ConfirmationResult(
            $signature,
            $outcome,
            $confirmationStatus,
            $slot,
            $error,
            $pollCount,
            $elapsed,
            $rebroadcastCount
        );
    }
}
