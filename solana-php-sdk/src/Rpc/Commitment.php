<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc;

/**
 * Commitment levels for Solana RPC queries.
 *
 * Solana uses optimistic confirmation; different commitment levels trade
 * off latency against certainty that a result won't be rolled back:
 *
 *   - PROCESSED:  The node has processed the block but it may be skipped.
 *                 Fastest, least safe. Suitable only for UI hints, never
 *                 for payment confirmation.
 *
 *   - CONFIRMED:  The block is voted on by a supermajority of the cluster.
 *                 Very unlikely to be rolled back in practice. A reasonable
 *                 balance for most applications.
 *
 *   - FINALIZED:  The block has been confirmed by 2/3+ of stake and is
 *                 committed permanently. Safest, highest latency (usually
 *                 ~13 seconds from submission). Use for settlement-grade
 *                 confirmation of payments or state changes that must not
 *                 be reversible.
 *
 * This is a constants-only class rather than a PHP 8.1 enum to preserve
 * PHP 8.0 compatibility.
 */
final class Commitment
{
    public const PROCESSED = 'processed';
    public const CONFIRMED = 'confirmed';
    public const FINALIZED = 'finalized';

    public const ALL = [self::PROCESSED, self::CONFIRMED, self::FINALIZED];

    public static function isValid(string $commitment): bool
    {
        return in_array($commitment, self::ALL, true);
    }

    private function __construct()
    {
        // Constants-only; no instances.
    }
}
