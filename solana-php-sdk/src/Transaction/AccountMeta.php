<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Keypair\PublicKey;

/**
 * Metadata for a single account referenced by an instruction.
 *
 * Every account that appears in an instruction must declare two things:
 *
 *   - Whether the account's owner must SIGN the transaction for this
 *     instruction to execute. The Solana runtime verifies all required
 *     signatures before any program code runs.
 *
 *   - Whether the account is WRITABLE. Writable accounts can have their
 *     lamport balance or data modified; readonly accounts cannot. The
 *     runtime uses this distinction to parallelize transactions that
 *     don't conflict on writable accounts.
 *
 * Order of AccountMeta entries within an instruction is significant: programs
 * typically expect a specific account layout and address each account by its
 * position in the list.
 *
 * Example: a SPL Token transfer instruction expects accounts in the order
 * [source, destination, owner], with owner as signer and source+destination
 * as writable.
 */
final class AccountMeta
{
    public PublicKey $pubkey;
    public bool $isSigner;
    public bool $isWritable;

    public function __construct(PublicKey $pubkey, bool $isSigner, bool $isWritable)
    {
        $this->pubkey = $pubkey;
        $this->isSigner = $isSigner;
        $this->isWritable = $isWritable;
    }

    /**
     * An account that signs the transaction and can be modified.
     * Typical example: the fee payer or a token account owner.
     */
    public static function signerWritable(PublicKey $pubkey): self
    {
        return new self($pubkey, true, true);
    }

    /**
     * An account that signs the transaction but cannot be modified.
     * Typical example: a delegate authority that authorizes but doesn't own.
     */
    public static function signerReadonly(PublicKey $pubkey): self
    {
        return new self($pubkey, true, false);
    }

    /**
     * An account that can be modified but does not sign.
     * Typical example: a recipient token account.
     */
    public static function writable(PublicKey $pubkey): self
    {
        return new self($pubkey, false, true);
    }

    /**
     * An account that is neither a signer nor writable. Used for
     * reference data like program IDs, sysvars, or lookup accounts.
     */
    public static function readonly(PublicKey $pubkey): self
    {
        return new self($pubkey, false, false);
    }
}
