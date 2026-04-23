<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Fee;

use SolanaPhpSdk\Exception\InvalidArgumentException;

/**
 * A full set of priority-fee estimates across all five levels.
 *
 * All values are in micro-lamports per compute unit (the unit the
 * ComputeBudgetProgram's SetComputeUnitPrice instruction accepts).
 *
 * To compute the total prioritization fee in lamports for a transaction:
 *
 *   totalMicroLamports = computeUnitPrice * computeUnitLimit
 *   lamports          = totalMicroLamports / 1_000_000   (rounded up)
 *
 * Example: a typical SPL token transfer with CU limit 200_000 and
 * computeUnitPrice 50_000 (medium priority on a moderately busy chain):
 *
 *   50_000 * 200_000 = 10_000_000_000 micro-lamports
 *                    = 10_000 lamports (~ $0.002 at $200/SOL)
 */
final class FeeEstimate
{
    public int $min;
    public int $low;
    public int $medium;
    public int $high;
    public int $veryHigh;

    /** Human-readable name of the estimator that produced this result, for debugging. */
    public string $source;

    public function __construct(
        int $min,
        int $low,
        int $medium,
        int $high,
        int $veryHigh,
        string $source = 'unknown'
    ) {
        foreach ([$min, $low, $medium, $high, $veryHigh] as $v) {
            if ($v < 0) {
                throw new InvalidArgumentException('Fee estimates must be non-negative');
            }
        }
        $this->min = $min;
        $this->low = $low;
        $this->medium = $medium;
        $this->high = $high;
        $this->veryHigh = $veryHigh;
        $this->source = $source;
    }

    /**
     * @param string $level One of {@see PriorityLevel}.
     */
    public function get(string $level): int
    {
        switch ($level) {
            case PriorityLevel::MIN:       return $this->min;
            case PriorityLevel::LOW:       return $this->low;
            case PriorityLevel::MEDIUM:    return $this->medium;
            case PriorityLevel::HIGH:      return $this->high;
            case PriorityLevel::VERY_HIGH: return $this->veryHigh;
            default:
                throw new InvalidArgumentException("Unknown priority level: {$level}");
        }
    }

    /**
     * @return array{min: int, low: int, medium: int, high: int, veryHigh: int, source: string}
     */
    public function toArray(): array
    {
        return [
            'min'      => $this->min,
            'low'      => $this->low,
            'medium'   => $this->medium,
            'high'     => $this->high,
            'veryHigh' => $this->veryHigh,
            'source'   => $this->source,
        ];
    }
}
