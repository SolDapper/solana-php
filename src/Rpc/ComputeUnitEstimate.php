<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc;

/**
 * Result of a compute-unit estimation via {@see ComputeUnitEstimator}.
 *
 * Immutable value object. The primary field of interest is
 * {@see self::$recommendedLimit} - the value a caller should pass to
 * {@see \SolanaPhpSdk\Programs\ComputeBudgetProgram::setComputeUnitLimit}
 * to balance "don't fail on chain from hitting the limit" against
 * "don't overpay priority fees by budgeting 20x what you actually need."
 */
final class ComputeUnitEstimate
{
    /** Actual compute units the simulator reported the transaction consuming. */
    public int $unitsConsumed;

    /** CU limit the caller should use, equal to ceil(unitsConsumed * multiplier), floored at $floor. */
    public int $recommendedLimit;

    /** The safety multiplier applied (e.g. 1.1 for 10% headroom). */
    public float $multiplier;

    /** The absolute minimum CU limit, regardless of how tiny the simulation was. */
    public int $floor;

    /**
     * Program log lines from the simulation, useful for debugging.
     *
     * Populated even when simulation succeeds - you can read these to
     * confirm the transaction's instructions executed the way you expected.
     *
     * @var array<int, string>
     */
    public array $simulationLogs;

    /**
     * True if simulation ran without an error result.
     *
     * Note: even when this is true, $unitsConsumed reflects what the
     * simulator observed - if the transaction's logic is wrong but
     * doesn't abort (e.g. it returns early on a condition), the CU
     * number may be much lower than production reality. Review
     * $simulationLogs if a result looks suspiciously small.
     */
    public bool $simulationSucceeded;

    /**
     * The raw error object from simulation, if any. Null when simulation
     * succeeded. Useful for distinguishing "simulation failed because the
     * tx itself is buggy" from network errors.
     *
     * @var mixed
     */
    public $simulationError;

    /**
     * @param array<int, string> $simulationLogs
     * @param mixed $simulationError
     */
    public function __construct(
        int $unitsConsumed,
        int $recommendedLimit,
        float $multiplier,
        int $floor,
        array $simulationLogs,
        bool $simulationSucceeded,
        $simulationError = null
    ) {
        $this->unitsConsumed = $unitsConsumed;
        $this->recommendedLimit = $recommendedLimit;
        $this->multiplier = $multiplier;
        $this->floor = $floor;
        $this->simulationLogs = $simulationLogs;
        $this->simulationSucceeded = $simulationSucceeded;
        $this->simulationError = $simulationError;
    }
}
