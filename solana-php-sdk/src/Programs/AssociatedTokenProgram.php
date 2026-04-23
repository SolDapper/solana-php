<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Programs;

use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Transaction\AccountMeta;
use SolanaPhpSdk\Transaction\TransactionInstruction;

/**
 * Instruction builders for the Associated Token Account (ATA) program.
 *
 * An Associated Token Account is a deterministically-addressed token
 * account for a (wallet, mint) pair. Rather than tracking a user-specific
 * token account per wallet, applications derive the ATA address using a
 * PDA and either use it directly (if it exists) or create it on the fly.
 *
 * For payment integrations this program is critical:
 *
 *   - A merchant computes the customer's ATA address (off-chain, via
 *     {@see \SolanaPhpSdk\Keypair\PublicKey::findProgramAddress}) before
 *     the customer even submits a transaction, allowing them to show
 *     "send X USDC to this address" in the UI.
 *
 *   - When receiving payouts, the merchant needs an ATA for their own
 *     wallet for each accepted token. {@see self::createIdempotent()} is
 *     the safe way to create it: if it already exists the instruction is
 *     a no-op, so the same transaction shape works for every payment
 *     regardless of whether the merchant's ATA has been initialized yet.
 *
 * Wire format:
 *
 *   The original Create instruction has EMPTY instruction data.
 *   The Idempotent variant uses [0x01] as data (single byte).
 *
 * Both variants take the same 6-account list:
 *   [payer (sw), ata (w), owner (r), mint (r), systemProgram (r), tokenProgram (r)]
 *
 * Note: the Rent sysvar was part of the original account list but was
 * removed in newer ATA program versions. Modern web3.js omits it and this
 * builder matches that.
 *
 * {@see \SolanaPhpSdk\Keypair\PublicKey::findProgramAddress} for deriving
 * the ATA address from seeds [owner, tokenProgram, mint].
 */
final class AssociatedTokenProgram
{
    public const PROGRAM_ID = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL';

    private const IX_CREATE_IDEMPOTENT = 0x01;

    private function __construct()
    {
    }

    public static function programId(): PublicKey
    {
        return new PublicKey(self::PROGRAM_ID);
    }

    /**
     * Compute the ATA address for a (owner, mint) pair under the given token program.
     *
     * This is a pure off-chain computation — no RPC needed. The result is
     * deterministic: every caller computes the same ATA for the same inputs.
     *
     * @return array{0: PublicKey, 1: int} [ataAddress, bumpSeed]
     */
    public static function findAssociatedTokenAddress(
        PublicKey $owner,
        PublicKey $mint,
        ?PublicKey $tokenProgramId = null
    ): array {
        $tokenProgram = $tokenProgramId ?? TokenProgram::programId();

        return PublicKey::findProgramAddress(
            [
                $owner->toBytes(),
                $tokenProgram->toBytes(),
                $mint->toBytes(),
            ],
            self::programId()
        );
    }

    /**
     * Create an Associated Token Account, failing if it already exists.
     *
     * Use {@see self::createIdempotent()} in payment flows where concurrent
     * or retried transactions might create the same ATA.
     *
     * @param PublicKey $payer         Pays the rent (~0.002 SOL) and must sign.
     * @param PublicKey $associatedToken The derived ATA address.
     * @param PublicKey $owner         The wallet the ATA belongs to.
     * @param PublicKey $mint          The token mint.
     * @param PublicKey|null $tokenProgramId Optional override for Token-2022.
     */
    public static function create(
        PublicKey $payer,
        PublicKey $associatedToken,
        PublicKey $owner,
        PublicKey $mint,
        ?PublicKey $tokenProgramId = null
    ): TransactionInstruction {
        return new TransactionInstruction(
            self::programId(),
            self::buildAccounts($payer, $associatedToken, $owner, $mint, $tokenProgramId),
            '' // Non-idempotent variant has empty data.
        );
    }

    /**
     * Create an Associated Token Account if it doesn't already exist.
     *
     * The preferred variant for payment integrations: if the ATA already
     * exists this is a no-op (and costs no rent). If it doesn't exist,
     * it's created. Either way the rest of the transaction proceeds.
     *
     * Typical pattern for receiving USDC:
     *   1. createIdempotent(merchant ATA)
     *   2. transferChecked from customer ATA to merchant ATA
     *
     * Both instructions in the same transaction execute atomically.
     */
    public static function createIdempotent(
        PublicKey $payer,
        PublicKey $associatedToken,
        PublicKey $owner,
        PublicKey $mint,
        ?PublicKey $tokenProgramId = null
    ): TransactionInstruction {
        return new TransactionInstruction(
            self::programId(),
            self::buildAccounts($payer, $associatedToken, $owner, $mint, $tokenProgramId),
            chr(self::IX_CREATE_IDEMPOTENT)
        );
    }

    /**
     * @return array<int, AccountMeta>
     */
    private static function buildAccounts(
        PublicKey $payer,
        PublicKey $associatedToken,
        PublicKey $owner,
        PublicKey $mint,
        ?PublicKey $tokenProgramId
    ): array {
        $tokenProgram = $tokenProgramId ?? TokenProgram::programId();

        return [
            AccountMeta::signerWritable($payer),
            AccountMeta::writable($associatedToken),
            AccountMeta::readonly($owner),
            AccountMeta::readonly($mint),
            AccountMeta::readonly(SystemProgram::programId()),
            AccountMeta::readonly($tokenProgram),
        ];
    }
}
