<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Fee;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Rpc\RpcClient;

/**
 * Percentile-based fee estimator using the standard Solana RPC method.
 *
 * Works with ANY RPC provider since it only uses the core
 * {@see RpcClient::getRecentPrioritizationFees()} method. The trade-off
 * versus a provider-native estimator (Helius, Triton) is accuracy: this
 * estimator sees only what the RPC node happened to sample recently,
 * whereas provider-native estimators often have wider visibility into
 * network-wide fee pressure.
 *
 * Percentile assignments (tunable at construction):
 *   MIN        = 25th percentile
 *   LOW        = 50th percentile
 *   MEDIUM     = 75th percentile
 *   HIGH       = 90th percentile
 *   VERY_HIGH  = 95th percentile
 *
 * These are conservative defaults. If the node's sample contains many
 * zero-fee transactions (common in quiet periods), low percentiles can
 * land on zero — which is correct but potentially surprising.
 */
final class StandardFeeEstimator implements FeeEstimator
{
    private RpcClient $rpc;

    /** @var array<string, float> */
    private array $percentiles;

    /**
     * Minimum floor applied to every bucket. Useful to guard against the
     * "everyone's paying zero so estimate is zero, but my transaction still
     * needs SOMETHING to land" failure mode.
     */
    private int $minFloor;

    /**
     * @param array<string, float>|null $percentiles Override the default
     *        bucket-to-percentile mapping. Keys must be {@see PriorityLevel}
     *        constants; values are percentiles in [0, 100].
     */
    public function __construct(RpcClient $rpc, ?array $percentiles = null, int $minFloor = 0)
    {
        $this->rpc = $rpc;
        $this->minFloor = max(0, $minFloor);
        $this->percentiles = $percentiles ?? [
            PriorityLevel::MIN       => 25.0,
            PriorityLevel::LOW       => 50.0,
            PriorityLevel::MEDIUM    => 75.0,
            PriorityLevel::HIGH      => 90.0,
            PriorityLevel::VERY_HIGH => 95.0,
        ];

        // Validate percentile map.
        foreach (PriorityLevel::ALL as $level) {
            if (!isset($this->percentiles[$level])) {
                throw new InvalidArgumentException("Percentile map missing level: {$level}");
            }
            $p = $this->percentiles[$level];
            if ($p < 0 || $p > 100) {
                throw new InvalidArgumentException("Percentile for {$level} must be in [0, 100], got {$p}");
            }
        }
    }

    public function estimate(array $writableAccounts = []): FeeEstimate
    {
        $samples = $this->rpc->getRecentPrioritizationFees($writableAccounts);
        $fees = [];
        foreach ($samples as $entry) {
            $fees[] = (int) $entry['prioritizationFee'];
        }

        // Compute percentiles on the sampled distribution.
        $vals = [];
        foreach (PriorityLevel::ALL as $level) {
            $v = $this->percentile($fees, $this->percentiles[$level]);
            $vals[$level] = max($this->minFloor, $v);
        }

        return new FeeEstimate(
            $vals[PriorityLevel::MIN],
            $vals[PriorityLevel::LOW],
            $vals[PriorityLevel::MEDIUM],
            $vals[PriorityLevel::HIGH],
            $vals[PriorityLevel::VERY_HIGH],
            'standard-percentile'
        );
    }

    public function estimateLevel(array $writableAccounts, string $level): int
    {
        return $this->estimate($writableAccounts)->get($level);
    }

    /**
     * Nearest-rank percentile (simple, matches common Solana tooling behavior).
     *
     * Returns 0 for an empty sample set — callers can override via $minFloor.
     *
     * @param array<int, int> $values
     */
    public static function percentile(array $values, float $p): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $n = count($values);

        // Linear interpolation between order statistics, matching NumPy's
        // default "linear" method. Gives smoother results than nearest-rank
        // on small samples without being surprising on large ones.
        $rank = ($p / 100.0) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) {
            return (int) $values[$lo];
        }
        $frac = $rank - $lo;
        $interp = $values[$lo] + ($values[$hi] - $values[$lo]) * $frac;
        return (int) round($interp);
    }
}
