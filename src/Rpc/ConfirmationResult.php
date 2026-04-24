<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc;

/**
 * Outcome of awaiting a transaction's confirmation.
 *
 * The terminal {@see self::$outcome} field is the primary signal. The
 * other fields are useful for debugging, audit logging, or distinguishing
 * "transaction succeeded" from "transaction landed but errored on chain"
 * (a real distinction in Solana - a transaction can be fully confirmed
 * AND have an execution error).
 */
final class ConfirmationResult
{
    public const OUTCOME_CONFIRMED = 'confirmed';
    public const OUTCOME_FINALIZED = 'finalized';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_EXPIRED = 'expired';
    public const OUTCOME_TIMEOUT = 'timeout';

    public string $signature;
    public string $outcome;
    public ?string $confirmationStatus;
    public ?int $slot;

    /** @var mixed Solana on-chain error, if any. Various shapes; null on success. */
    public $error;

    public int $pollCount;
    public int $elapsedSeconds;

    /**
     * Number of rebroadcast attempts made during the wait. Always 0 if the
     * caller did not configure rebroadcast via {@see ConfirmationOptions::withRebroadcast()}.
     */
    public int $rebroadcastCount;

    /** @param mixed $error */
    public function __construct(
        string $signature,
        string $outcome,
        ?string $confirmationStatus,
        ?int $slot,
        $error,
        int $pollCount,
        int $elapsedSeconds,
        int $rebroadcastCount = 0
    ) {
        $this->signature = $signature;
        $this->outcome = $outcome;
        $this->confirmationStatus = $confirmationStatus;
        $this->slot = $slot;
        $this->error = $error;
        $this->pollCount = $pollCount;
        $this->elapsedSeconds = $elapsedSeconds;
        $this->rebroadcastCount = $rebroadcastCount;
    }

    /**
     * True if the transaction is on chain at the requested commitment
     * level (or stronger) and did NOT error during execution.
     */
    public function isSuccess(): bool
    {
        return $this->outcome === self::OUTCOME_CONFIRMED
            || $this->outcome === self::OUTCOME_FINALIZED;
    }
}
