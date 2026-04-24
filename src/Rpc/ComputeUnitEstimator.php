<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\ComputeBudgetProgram;
use SolanaPhpSdk\Transaction\AddressLookupTableAccount;
use SolanaPhpSdk\Transaction\MessageV0;
use SolanaPhpSdk\Transaction\Transaction;
use SolanaPhpSdk\Transaction\TransactionInstruction;
use SolanaPhpSdk\Transaction\VersionedTransaction;

/**
 * Estimates the compute-unit budget a transaction will actually need.
 *
 * The Solana runtime charges priority fees as (compute_unit_price *
 * compute_unit_limit). A naive default like 200,000 CU works for most
 * payment transactions but has two problems:
 *
 *   1. It's too low for heavier transactions (DEX swaps, NFT mints with
 *      lots of CPI) and causes "instruction exceeded maximum compute
 *      units" failures on chain.
 *
 *   2. It's way too high for simple transfers. A plain SOL transfer uses
 *      ~450 CU; budgeting 200,000 means you're overpaying priority fees
 *      by ~400x when the chain is congested. On thousands of transactions
 *      that adds up fast.
 *
 * This estimator uses the RPC's `simulateTransaction` method to measure
 * actual CU consumption, then applies a safety multiplier so minor
 * slot-to-slot variation doesn't cause on-chain failures.
 *
 * The typical flow from the caller's perspective:
 *
 *   1. Build the list of instructions for your transaction (without any
 *      ComputeBudgetProgram instructions).
 *   2. Call estimateLegacy() or estimateV0() to get a {@see ComputeUnitEstimate}.
 *   3. Build the final transaction with a
 *      ComputeBudgetProgram::setComputeUnitLimit($estimate->recommendedLimit)
 *      instruction prepended to your instruction list.
 *   4. Sign and submit.
 *
 * The estimator itself doesn't mutate any caller-supplied data - it
 * internally constructs a placeholder transaction to simulate and
 * returns the numbers.
 *
 * For higher-level users, {@see \SolanaPhpSdk\Programs\PaymentBuilder}
 * wraps this in a fluent `withSimulatedComputeUnitLimit()` method.
 *
 * Reference behavior: this matches web3.js's pattern of simulating with
 * `replaceRecentBlockhash: true, sigVerify: false` (so the caller doesn't
 * need to hold private keys or a fresh blockhash just to estimate) and
 * reading `value.unitsConsumed` from the response.
 */
final class ComputeUnitEstimator
{
    /**
     * The placeholder CU limit used during simulation. Must be high enough
     * that no real transaction's CU consumption is constrained by it
     * (otherwise the simulator would report a lower-bound rather than
     * actual usage). The Solana per-transaction maximum is 1,400,000.
     */
    public const SIMULATION_PLACEHOLDER_LIMIT = 1_400_000;

    private RpcClient $rpc;

    public function __construct(RpcClient $rpc)
    {
        $this->rpc = $rpc;
    }

    /**
     * Estimate CU consumption for a legacy transaction.
     *
     * @param array<int, TransactionInstruction> $instructions
     *        The caller's business-logic instructions. MUST NOT include
     *        any ComputeBudgetProgram::setComputeUnitLimit instructions -
     *        the estimator adds its own placeholder. ComputeUnitPrice ix
     *        are OK to include (they don't affect CU consumption).
     * @param PublicKey $feePayer The transaction fee payer.
     * @param string $blockhash A recent blockhash - Base58 string or 32 raw bytes.
     *        With replaceRecentBlockhash: true during simulation (the default)
     *        this doesn't need to be fresh, but it does need to be a valid
     *        32-byte value for the compile step to succeed.
     * @param float $multiplier Safety margin applied to unitsConsumed. Default 1.1
     *        (10% headroom). Use 1.2+ for transactions touching volatile state
     *        (oracles, AMMs) where slot-to-slot CU variance is higher.
     * @param int $floor Minimum recommended limit regardless of simulation result.
     *        Guards against edge cases where simulation reports implausibly low
     *        usage. Default 1000.
     *
     * @throws InvalidArgumentException If $multiplier < 1 or $floor < 0.
     * @throws RpcException If simulation's RPC call itself errors (network failure,
     *        provider rejection, etc.). Note: if simulation *succeeds* but reports
     *        an execution error inside the transaction, this method returns a
     *        ComputeUnitEstimate with simulationSucceeded=false rather than throwing.
     */
    public function estimateLegacy(
        array $instructions,
        PublicKey $feePayer,
        string $blockhash,
        float $multiplier = 1.1,
        int $floor = 1000
    ): ComputeUnitEstimate {
        $this->validateParams($multiplier, $floor);

        // Inject the max-CU placeholder so simulation isn't constrained by an
        // existing limit. If the caller already included a setComputeUnitLimit
        // instruction, our placeholder appears alongside it, and the LAST one
        // wins per the runtime's semantics (the runtime processes all such ix
        // in order and uses the final value). Placing ours last guarantees
        // our value is what applies.
        $simulationIxs = array_merge(
            $instructions,
            [ComputeBudgetProgram::setComputeUnitLimit(self::SIMULATION_PLACEHOLDER_LIMIT)]
        );

        $tx = Transaction::new($simulationIxs, $feePayer, $blockhash);
        // Signatures stay zero-filled - with sigVerify: false the simulator
        // doesn't care.

        return $this->simulateAndInterpret($tx, $multiplier, $floor);
    }

    /**
     * Estimate CU consumption for a v0 (versioned) transaction.
     *
     * Same contract as estimateLegacy, plus address lookup table support.
     *
     * @param array<int, TransactionInstruction> $instructions
     * @param array<int, AddressLookupTableAccount> $addressLookupTables
     *
     * @throws InvalidArgumentException If $multiplier < 1 or $floor < 0.
     * @throws RpcException On RPC-level failures.
     */
    public function estimateV0(
        array $instructions,
        PublicKey $feePayer,
        string $blockhash,
        array $addressLookupTables = [],
        float $multiplier = 1.1,
        int $floor = 1000
    ): ComputeUnitEstimate {
        $this->validateParams($multiplier, $floor);

        $simulationIxs = array_merge(
            $instructions,
            [ComputeBudgetProgram::setComputeUnitLimit(self::SIMULATION_PLACEHOLDER_LIMIT)]
        );

        $message = MessageV0::compile($feePayer, $simulationIxs, $blockhash, $addressLookupTables);
        $tx = new VersionedTransaction($message);

        return $this->simulateAndInterpret($tx, $multiplier, $floor);
    }

    /**
     * Common simulation + result-interpretation path used by both the
     * legacy and v0 entry points.
     *
     * @param Transaction|VersionedTransaction $tx
     */
    private function simulateAndInterpret($tx, float $multiplier, int $floor): ComputeUnitEstimate
    {
        $response = $this->rpc->simulateTransaction($tx, [
            'replaceRecentBlockhash' => true,
            'sigVerify'              => false,
        ]);

        $unitsConsumed = $this->parseUnitsConsumed($response);
        $logs = $this->parseLogs($response);
        $err = $response['err'] ?? null;
        $succeeded = ($err === null);

        // Compute recommended limit: ceil(consumed * multiplier), floored.
        // If simulation failed entirely (err != null) and we still got a
        // unitsConsumed value, we still apply the same math - some RPC
        // providers report partial CU usage even on failure.
        $scaled = (int) ceil($unitsConsumed * $multiplier);
        $recommended = max($scaled, $floor);
        // Never exceed the runtime's hard cap of 1,400,000 CU per transaction.
        $recommended = min($recommended, self::SIMULATION_PLACEHOLDER_LIMIT);

        return new ComputeUnitEstimate(
            $unitsConsumed,
            $recommended,
            $multiplier,
            $floor,
            $logs,
            $succeeded,
            $err
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function parseUnitsConsumed(array $response): int
    {
        if (!isset($response['unitsConsumed'])) {
            // Some providers omit unitsConsumed if simulation failed very early.
            // We return 0 here and let the caller see simulationSucceeded=false.
            return 0;
        }
        // u64 normalization - int if it fits, else the string form.
        $raw = $response['unitsConsumed'];
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw)) {
            // If we ever see a u64 CU count larger than PHP_INT_MAX, something
            // is very wrong - the runtime caps at 1.4M CU. But handle gracefully.
            return (int) $raw;
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, string>
     */
    private function parseLogs(array $response): array
    {
        $logs = $response['logs'] ?? [];
        if (!is_array($logs)) {
            return [];
        }
        $out = [];
        foreach ($logs as $line) {
            if (is_string($line)) {
                $out[] = $line;
            }
        }
        return $out;
    }

    private function validateParams(float $multiplier, int $floor): void
    {
        if ($multiplier < 1.0) {
            throw new InvalidArgumentException(
                'Multiplier must be >= 1.0 (no headroom). Got ' . $multiplier
            );
        }
        if ($floor < 0) {
            throw new InvalidArgumentException(
                'Floor must be non-negative. Got ' . $floor
            );
        }
    }
}
