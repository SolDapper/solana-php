<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Fee;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\RpcClient;

/**
 * Fee estimator for Triton One's extended getRecentPrioritizationFees.
 *
 * Triton's extension adds a second parameter `{percentile: <basisPoints>}`
 * where basis points are in the range 1..10000 (so 5000 = 50th percentile).
 * The response shape is identical to the standard method but the
 * `prioritizationFee` field reflects the requested percentile rather than
 * the minimum.
 *
 * This estimator makes one RPC call per priority level (5 calls total).
 * That's more requests than Helius's single call, but it uses server-side
 * percentile computation — accurate across the full slot window rather
 * than whatever subset the client happened to sample.
 *
 * Requires the RpcClient to point at a Triton-compatible endpoint. Against
 * a vanilla RPC the extra percentile parameter will be silently ignored
 * and every bucket will come back with the global minimum — the estimator
 * will still "work" but produce degenerate flat output. Callers that care
 * should verify with a test probe or just use {@see StandardFeeEstimator}
 * if they aren't on Triton.
 */
final class TritonFeeEstimator implements FeeEstimator
{
    private RpcClient $rpc;

    /** @var array<string, int> Basis-point percentile per level. */
    private array $percentileBps;

    /**
     * @param array<string, int>|null $percentileBps Override percentile
     *        assignments. Keys must be {@see PriorityLevel}, values 1..10000.
     */
    public function __construct(RpcClient $rpc, ?array $percentileBps = null)
    {
        $this->rpc = $rpc;
        $this->percentileBps = $percentileBps ?? [
            PriorityLevel::MIN       => 2500,  // 25th
            PriorityLevel::LOW       => 5000,  // 50th
            PriorityLevel::MEDIUM    => 7500,  // 75th
            PriorityLevel::HIGH      => 9000,  // 90th
            PriorityLevel::VERY_HIGH => 9500,  // 95th
        ];

        foreach (PriorityLevel::ALL as $level) {
            if (!isset($this->percentileBps[$level])) {
                throw new InvalidArgumentException("percentileBps missing level: {$level}");
            }
            $v = $this->percentileBps[$level];
            if (!is_int($v) || $v < 1 || $v > 10000) {
                throw new InvalidArgumentException("percentileBps[{$level}] must be int 1..10000, got {$v}");
            }
        }
    }

    public function estimate(array $writableAccounts = []): FeeEstimate
    {
        $addresses = [];
        foreach ($writableAccounts as $i => $pk) {
            if (!$pk instanceof PublicKey) {
                throw new InvalidArgumentException("writableAccounts[{$i}] must be a PublicKey instance");
            }
            $addresses[] = $pk->toBase58();
        }

        // Query each percentile independently. The Triton API doesn't batch.
        $results = [];
        foreach ($this->percentileBps as $level => $bps) {
            $params = [];
            // Triton requires BOTH params to be positional — the first is
            // the (possibly-empty) address list.
            $params[] = $addresses;
            $params[] = ['percentile' => $bps];

            $raw = $this->rpc->call('getRecentPrioritizationFees', $params);

            // Take the maximum observed value in the returned slot window
            // as the estimate for this percentile. (Triton applies the
            // percentile PER-SLOT, so the array still has one entry per
            // slot; we pick the top to capture spikes.)
            $max = 0;
            foreach ($raw as $entry) {
                $fee = (int) ($entry['prioritizationFee'] ?? 0);
                if ($fee > $max) {
                    $max = $fee;
                }
            }
            $results[$level] = $max;
        }

        return new FeeEstimate(
            $results[PriorityLevel::MIN],
            $results[PriorityLevel::LOW],
            $results[PriorityLevel::MEDIUM],
            $results[PriorityLevel::HIGH],
            $results[PriorityLevel::VERY_HIGH],
            'triton'
        );
    }

    public function estimateLevel(array $writableAccounts, string $level): int
    {
        return $this->estimate($writableAccounts)->get($level);
    }
}
