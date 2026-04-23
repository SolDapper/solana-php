<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Fee;

/**
 * Provider-agnostic priority bucket names for fee estimation.
 *
 * Every concrete {@see FeeEstimator} maps these buckets to its provider's
 * native taxonomy. The names and ordering follow Helius's convention, which
 * is the most widely adopted vocabulary in the ecosystem:
 *
 *   MIN < LOW < MEDIUM < HIGH < VERY_HIGH
 *
 * Percentile interpretation for the standard estimator:
 *   MIN        = 25th percentile of recent priority fees
 *   LOW        = 50th percentile
 *   MEDIUM     = 75th percentile
 *   HIGH       = 90th percentile
 *   VERY_HIGH  = 95th percentile
 *
 * These are the defaults; individual estimators may adjust. Callers can
 * always query the raw distribution via
 * {@see \SolanaPhpSdk\Rpc\RpcClient::getRecentPrioritizationFees()}
 * and compute their own percentiles if needed.
 *
 * Constants-only for PHP 8.0 compatibility.
 */
final class PriorityLevel
{
    public const MIN        = 'min';
    public const LOW        = 'low';
    public const MEDIUM     = 'medium';
    public const HIGH       = 'high';
    public const VERY_HIGH  = 'veryHigh';

    public const ALL = [
        self::MIN,
        self::LOW,
        self::MEDIUM,
        self::HIGH,
        self::VERY_HIGH,
    ];

    public static function isValid(string $level): bool
    {
        return in_array($level, self::ALL, true);
    }

    private function __construct()
    {
    }
}
