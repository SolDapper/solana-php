<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Programs;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Transaction\AccountMeta;
use SolanaPhpSdk\Transaction\TransactionInstruction;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Instruction builders for Solana's System native program.
 *
 * The System program owns every account at the moment of creation. It
 * implements the fundamental account lifecycle operations: create account,
 * transfer SOL, allocate space, and change ownership.
 *
 * For payment applications, {@see self::transfer()} is by far the most
 * important method — it moves native SOL between accounts. Other methods
 * are needed when creating token accounts from scratch (though the usual
 * path uses {@see AssociatedTokenProgram::createIdempotent()} instead,
 * which is simpler and atomic with deposits).
 *
 * Wire format (SystemInstruction enum in solana-sdk/src/system_instruction.rs):
 *   Instruction data = [u32 LE discriminator] + [payload]
 *
 *   0: CreateAccount     { lamports: u64, space: u64, owner: Pubkey }
 *   1: Assign            { owner: Pubkey }
 *   2: Transfer          { lamports: u64 }
 *   3: CreateAccountWithSeed ... (not implemented here; rare in payments)
 *   ...
 *   8: Allocate          { space: u64 }
 *
 * Note: System uses a u32 discriminator (4 bytes), unlike ComputeBudget
 * which uses u8 (1 byte) and SPL Token which also uses u8. This is a
 * Rust-bincode artifact — the enum variant index is serialized as u32 by
 * default in bincode.
 */
final class SystemProgram
{
    public const PROGRAM_ID = '11111111111111111111111111111111';

    private const IX_CREATE_ACCOUNT = 0;
    private const IX_ASSIGN         = 1;
    private const IX_TRANSFER       = 2;
    private const IX_ALLOCATE       = 8;

    private function __construct()
    {
    }

    public static function programId(): PublicKey
    {
        return new PublicKey(self::PROGRAM_ID);
    }

    /**
     * Transfer SOL (native lamports) from one account to another.
     *
     * Both accounts must be writable. The sender must sign the transaction.
     *
     * @param int|string $lamports Amount in lamports (1 SOL = 1e9 lamports).
     *                             Accept numeric string for values exceeding PHP_INT_MAX.
     */
    public static function transfer(PublicKey $from, PublicKey $to, $lamports): TransactionInstruction
    {
        $data = (new ByteBuffer())
            ->writeU32(self::IX_TRANSFER)
            ->writeU64($lamports)
            ->toBytes();

        return new TransactionInstruction(
            self::programId(),
            [
                AccountMeta::signerWritable($from),
                AccountMeta::writable($to),
            ],
            $data
        );
    }

    /**
     * Create a brand-new account, funding it and assigning it to a program.
     *
     * The new account's keypair must sign the transaction (proving the
     * creator has the private key). After this instruction runs, the account
     * exists with `space` bytes allocated, `lamports` balance, and `owner`
     * as the program that can modify it.
     *
     * For creating token accounts, prefer
     * {@see AssociatedTokenProgram::createIdempotent()} — it's safer,
     * deterministic, and idempotent.
     *
     * @param int|string $lamports Initial balance.
     * @param int|string $space Data size in bytes.
     */
    public static function createAccount(
        PublicKey $from,
        PublicKey $newAccount,
        $lamports,
        $space,
        PublicKey $programOwner
    ): TransactionInstruction {
        $data = (new ByteBuffer())
            ->writeU32(self::IX_CREATE_ACCOUNT)
            ->writeU64($lamports)
            ->writeU64($space)
            ->writeBytes($programOwner->toBytes())
            ->toBytes();

        return new TransactionInstruction(
            self::programId(),
            [
                AccountMeta::signerWritable($from),
                AccountMeta::signerWritable($newAccount),
            ],
            $data
        );
    }

    /**
     * Change the owner program of an existing account.
     *
     * The account must currently be owned by the System program (or be
     * empty) and must sign the transaction.
     */
    public static function assign(PublicKey $account, PublicKey $newOwner): TransactionInstruction
    {
        $data = (new ByteBuffer())
            ->writeU32(self::IX_ASSIGN)
            ->writeBytes($newOwner->toBytes())
            ->toBytes();

        return new TransactionInstruction(
            self::programId(),
            [AccountMeta::signerWritable($account)],
            $data
        );
    }

    /**
     * Allocate space in an account without creating it.
     *
     * Used on accounts that already exist (were created but have zero
     * data size). The account must sign the transaction.
     *
     * @param int|string $space New size in bytes.
     */
    public static function allocate(PublicKey $account, $space): TransactionInstruction
    {
        $data = (new ByteBuffer())
            ->writeU32(self::IX_ALLOCATE)
            ->writeU64($space)
            ->toBytes();

        return new TransactionInstruction(
            self::programId(),
            [AccountMeta::signerWritable($account)],
            $data
        );
    }
}
