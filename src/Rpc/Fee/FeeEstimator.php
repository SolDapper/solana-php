<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Fee;

use SolanaPhpSdk\Keypair\PublicKey;

/**
 * Provider-agnostic priority-fee estimation contract.
 *
 * Solana transactions can include a "prioritization fee" (in micro-lamports
 * per compute unit) to improve their chances of landing in congested blocks.
 * There is no single correct estimation algorithm — providers disagree — so
 * the SDK ships a small family of estimator implementations and users pick
 * the one that matches their RPC provider.
 *
 * All estimators return a {@see FeeEstimate} with all five priority buckets
 * populated, plus the user's target bucket. Application code can work with
 * just the bucket it cares about without knowing which estimator produced
 * it.
 *
 * Implementations:
 *   - {@see StandardFeeEstimator}    Vanilla getRecentPrioritizationFees +
 *                                    percentile computation. Works with any
 *                                    provider.
 *   - {@see HeliusFeeEstimator}      Uses Helius's getPriorityFeeEstimate.
 *   - {@see TritonFeeEstimator}      Uses Triton's percentile-extended
 *                                    getRecentPrioritizationFees.
 *
 * See each concrete class for provider-specific details.
 */
interface FeeEstimator
{
    /**
     * Produce a full set of per-level estimates for a transaction touching
     * the given writable accounts.
     *
     * The write-account hint sharpens the estimate because priority fees
     * are contention-driven: a transaction locking a hot account (e.g. a
     * popular AMM pool) competes against other traffic for the same lock,
     * so its required fee is driven by that account's contention, not the
     * global median.
     *
     * @param array<int, PublicKey> $writableAccounts The writable (non-signer
     *        and signer) accounts the transaction will touch. Pass an empty
     *        array for a global estimate.
     */
    public function estimate(array $writableAccounts = []): FeeEstimate;

    /**
     * Shortcut for callers that only want one bucket's value.
     *
     * @param array<int, PublicKey> $writableAccounts
     * @param string $level One of {@see PriorityLevel}.
     * @return int Micro-lamports per compute unit.
     */
    public function estimateLevel(array $writableAccounts, string $level): int;
}
