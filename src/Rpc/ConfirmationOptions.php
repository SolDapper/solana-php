<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc;

use SolanaPhpSdk\Exception\InvalidArgumentException;

/**
 * Configuration for awaiting transaction confirmation.
 *
 * Default values target the common ecommerce case: wait for `confirmed`
 * commitment (~1-2s on mainnet), give up after 60 seconds (the blockhash
 * validity window), poll every 1-5 seconds with a gentle backoff.
 *
 * For high-value transactions or audit trails, use {@see self::finalized()}
 * which waits for the block to be ~13+ seconds finalized and impossible to
 * roll back. Most ecom checkouts use confirmed for UX speed; high-value
 * flows use confirmed for the immediate UX, then re-poll for finalized in
 * a background job.
 *
 * Static factories ({@see self::confirmed()}, {@see self::finalized()},
 * {@see self::processed()}) cover the common cases. For more control,
 * construct directly and set fields.
 */
final class ConfirmationOptions
{
    /** Target commitment level. One of {@see Commitment} constants. */
    public string $commitment;

    /** Maximum wall-clock time to wait, in seconds. */
    public int $timeoutSeconds;

    /** Initial poll interval after submission. */
    public int $initialPollInterval;

    /** Cap on poll interval after backoff. */
    public int $maxPollInterval;

    /**
     * If set, the confirmer will fetch the chain's current block height
     * during polling and abort with an EXPIRED outcome once the chain
     * passes this height. Get this value from the `lastValidBlockHeight`
     * field of `getLatestBlockhash()`.
     */
    public ?int $lastValidBlockHeight;

    /**
     * If set, the confirmer will re-broadcast these wire bytes via
     * sendRawTransaction every {@see self::$rebroadcastEvery} seconds
     * during the wait. Solana validators sometimes drop transactions
     * silently under load; rebroadcasting is the canonical fix.
     */
    public ?string $rebroadcastWireBytes;

    /** Seconds between rebroadcast attempts. Ignored if rebroadcastWireBytes is null. */
    public int $rebroadcastEvery;

    public function __construct(
        string $commitment = Commitment::CONFIRMED,
        int $timeoutSeconds = 60,
        int $initialPollInterval = 1,
        int $maxPollInterval = 5,
        ?int $lastValidBlockHeight = null,
        ?string $rebroadcastWireBytes = null,
        int $rebroadcastEvery = 5
    ) {
        if (!Commitment::isValid($commitment)) {
            throw new InvalidArgumentException("Invalid commitment level: {$commitment}");
        }
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException("timeoutSeconds must be >= 1, got {$timeoutSeconds}");
        }
        if ($initialPollInterval < 1) {
            throw new InvalidArgumentException("initialPollInterval must be >= 1, got {$initialPollInterval}");
        }
        if ($maxPollInterval < $initialPollInterval) {
            throw new InvalidArgumentException(
                "maxPollInterval ({$maxPollInterval}) must be >= initialPollInterval ({$initialPollInterval})"
            );
        }
        if ($rebroadcastEvery < 1) {
            throw new InvalidArgumentException("rebroadcastEvery must be >= 1, got {$rebroadcastEvery}");
        }

        $this->commitment = $commitment;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->initialPollInterval = $initialPollInterval;
        $this->maxPollInterval = $maxPollInterval;
        $this->lastValidBlockHeight = $lastValidBlockHeight;
        $this->rebroadcastWireBytes = $rebroadcastWireBytes;
        $this->rebroadcastEvery = $rebroadcastEvery;
    }

    /** Common ecommerce default: wait for `confirmed`, 60-second timeout. */
    public static function confirmed(int $timeoutSeconds = 60): self
    {
        return new self(Commitment::CONFIRMED, $timeoutSeconds);
    }

    /** Wait for finalized commitment (~13+ seconds typical on mainnet). */
    public static function finalized(int $timeoutSeconds = 90): self
    {
        return new self(Commitment::FINALIZED, $timeoutSeconds);
    }

    /** Wait only for `processed`. Liveness only - do not use for payments. */
    public static function processed(int $timeoutSeconds = 30): self
    {
        return new self(Commitment::PROCESSED, $timeoutSeconds);
    }

    /** Returns a copy with rebroadcast configured. */
    public function withRebroadcast(string $wireBytes, int $everySeconds = 5): self
    {
        $clone = clone $this;
        $clone->rebroadcastWireBytes = $wireBytes;
        $clone->rebroadcastEvery = $everySeconds;
        return $clone;
    }

    /** Returns a copy with blockhash expiry tracking enabled. */
    public function withBlockhashExpiry(int $lastValidBlockHeight): self
    {
        $clone = clone $this;
        $clone->lastValidBlockHeight = $lastValidBlockHeight;
        return $clone;
    }
}
