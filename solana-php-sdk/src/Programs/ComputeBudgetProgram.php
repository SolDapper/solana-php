<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Programs;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Transaction\TransactionInstruction;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Instruction builders for Solana's ComputeBudget native program.
 *
 * The ComputeBudget program lets transactions declare two things that
 * directly affect landing probability and fee cost:
 *
 *   1. COMPUTE UNIT LIMIT (via setComputeUnitLimit).
 *      Default is 200,000 CUs per instruction, capped at 1,400,000 CUs per
 *      transaction. The limit is reserved at fee calculation time — the
 *      runtime pre-charges priority fees based on the limit you requested,
 *      not what you actually consume. Over-requesting wastes SOL; under-
 *      requesting aborts execution.
 *
 *      Best practice: simulate the transaction, read unitsConsumed, add
 *      ~10% margin, and set the limit. {@see \SolanaPhpSdk\Rpc\RpcClient::simulateTransaction}
 *
 *   2. COMPUTE UNIT PRICE (via setComputeUnitPrice).
 *      In micro-lamports per CU. The total prioritization fee paid is
 *      ceil(limit * price / 1_000_000) lamports. Use a
 *      {@see \SolanaPhpSdk\Rpc\Fee\FeeEstimator} to pick a sensible value.
 *
 * Wire format (from solana-sdk/src/compute_budget.rs):
 *   All instructions have EMPTY account lists.
 *   Instruction data = [u8 discriminator] + [payload (u32 or u64 LE)]
 *
 *     0x00: Unused (deprecated)
 *     0x01: RequestHeapFrame(u32 bytes)   — 32KiB..256KiB, multiple of 1024
 *     0x02: SetComputeUnitLimit(u32 units)
 *     0x03: SetComputeUnitPrice(u64 microLamports)
 *     0x04: SetLoadedAccountsDataSizeLimit(u32 bytes)
 *
 * At most one of each type per transaction; duplicates cause
 * DuplicateInstruction and the whole transaction fails.
 */
final class ComputeBudgetProgram
{
    public const PROGRAM_ID = 'ComputeBudget111111111111111111111111111111';

    // Discriminator bytes.
    private const IX_REQUEST_HEAP_FRAME           = 0x01;
    private const IX_SET_COMPUTE_UNIT_LIMIT       = 0x02;
    private const IX_SET_COMPUTE_UNIT_PRICE       = 0x03;
    private const IX_SET_LOADED_ACCOUNTS_DATA_LIMIT = 0x04;

    // Runtime constants (mirror solana-program values).
    public const MAX_COMPUTE_UNIT_LIMIT  = 1_400_000;
    public const MIN_HEAP_FRAME_BYTES    = 32 * 1024;
    public const MAX_HEAP_FRAME_BYTES    = 256 * 1024;
    public const HEAP_FRAME_ALIGNMENT    = 1024;

    private function __construct()
    {
        // Static only.
    }

    public static function programId(): PublicKey
    {
        return new PublicKey(self::PROGRAM_ID);
    }

    /**
     * Set the maximum compute units the transaction may consume.
     *
     * Any u32 value is technically accepted; the runtime clamps the
     * effective value to {@see self::MAX_COMPUTE_UNIT_LIMIT}. Over-requesting
     * wastes SOL because priority fees are pre-charged on the requested
     * limit, not actual usage.
     */
    public static function setComputeUnitLimit(int $units): TransactionInstruction
    {
        if ($units < 0 || $units > 0xFFFFFFFF) {
            throw new InvalidArgumentException("units must fit in u32, got {$units}");
        }

        $data = (new ByteBuffer())
            ->writeU8(self::IX_SET_COMPUTE_UNIT_LIMIT)
            ->writeU32($units)
            ->toBytes();

        return new TransactionInstruction(self::programId(), [], $data);
    }

    /**
     * Set the compute unit price in micro-lamports.
     *
     * Total prioritization fee = ceil(cuLimit * microLamports / 1_000_000)
     * lamports. Use a {@see \SolanaPhpSdk\Rpc\Fee\FeeEstimator} to derive a
     * sensible value for current network conditions.
     *
     * @param int|string $microLamports u64 value (int or numeric string for
     *        amounts exceeding PHP_INT_MAX).
     */
    public static function setComputeUnitPrice($microLamports): TransactionInstruction
    {
        $data = (new ByteBuffer())
            ->writeU8(self::IX_SET_COMPUTE_UNIT_PRICE)
            ->writeU64($microLamports)
            ->toBytes();

        return new TransactionInstruction(self::programId(), [], $data);
    }

    /**
     * Request a larger transaction-wide program heap region.
     *
     * $bytes must be in [MIN_HEAP_FRAME_BYTES, MAX_HEAP_FRAME_BYTES] and a
     * multiple of HEAP_FRAME_ALIGNMENT. Rarely needed for typical payment
     * transactions.
     */
    public static function requestHeapFrame(int $bytes): TransactionInstruction
    {
        if ($bytes < self::MIN_HEAP_FRAME_BYTES || $bytes > self::MAX_HEAP_FRAME_BYTES) {
            throw new InvalidArgumentException(
                "bytes must be in [" . self::MIN_HEAP_FRAME_BYTES . ", " . self::MAX_HEAP_FRAME_BYTES . "]"
            );
        }
        if ($bytes % self::HEAP_FRAME_ALIGNMENT !== 0) {
            throw new InvalidArgumentException(
                'bytes must be a multiple of ' . self::HEAP_FRAME_ALIGNMENT
            );
        }
        $data = (new ByteBuffer())
            ->writeU8(self::IX_REQUEST_HEAP_FRAME)
            ->writeU32($bytes)
            ->toBytes();

        return new TransactionInstruction(self::programId(), [], $data);
    }

    /**
     * Set the maximum total account data size the transaction is allowed
     * to load. Must be non-zero; clamped at 64 MiB by the runtime.
     */
    public static function setLoadedAccountsDataSizeLimit(int $bytes): TransactionInstruction
    {
        if ($bytes <= 0 || $bytes > 0xFFFFFFFF) {
            throw new InvalidArgumentException("bytes must be a positive u32, got {$bytes}");
        }
        $data = (new ByteBuffer())
            ->writeU8(self::IX_SET_LOADED_ACCOUNTS_DATA_LIMIT)
            ->writeU32($bytes)
            ->toBytes();

        return new TransactionInstruction(self::programId(), [], $data);
    }
}
