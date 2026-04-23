<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Programs;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Transaction\AccountMeta;
use SolanaPhpSdk\Transaction\TransactionInstruction;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Instruction builders for the SPL Token program.
 *
 * SPL Token is the standard fungible-token program on Solana. USDC, USDT,
 * PYUSD, and virtually every other token uses it or its newer extension-
 * enabled variant (Token-2022). For ecommerce / payment flows, the methods
 * that matter are:
 *
 *   - {@see self::transfer()}           Simple transfer between token accounts.
 *   - {@see self::transferChecked()}    Preferred — includes mint + decimals,
 *                                       protecting against wrong-mint mistakes
 *                                       and wrong-decimal miscalculation.
 *
 * Wire format (from spl-token/program/src/instruction.rs):
 *   Instruction data = [u8 discriminator] + [payload]
 *
 *     0x03: Transfer         { amount: u64 }
 *     0x0c: TransferChecked  { amount: u64, decimals: u8 }
 *
 * Token-2022: the Token Extension program has the same instruction layouts
 * for basic transfer operations. To target Token-2022, construct your own
 * instruction via the low-level constructors and pass the Token-2022 program
 * ID ({@see self::TOKEN_2022_PROGRAM_ID}).
 */
final class TokenProgram
{
    /** Canonical SPL Token program ID. Covers USDC, USDT, and most tokens. */
    public const PROGRAM_ID = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

    /** Token-2022 (Token Extensions) program ID. */
    public const TOKEN_2022_PROGRAM_ID = 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb';

    private const IX_TRANSFER         = 0x03;
    private const IX_TRANSFER_CHECKED = 0x0c;

    private function __construct()
    {
    }

    public static function programId(): PublicKey
    {
        return new PublicKey(self::PROGRAM_ID);
    }

    public static function token2022ProgramId(): PublicKey
    {
        return new PublicKey(self::TOKEN_2022_PROGRAM_ID);
    }

    /**
     * Transfer tokens between two token accounts owned by the same mint.
     *
     * Less safe than {@see self::transferChecked()}: if the caller passes
     * the wrong source account (one holding a different mint) or miscounts
     * decimals, the transaction can still succeed and move an unintended
     * amount. For payment integrations prefer transferChecked.
     *
     * Account ordering:
     *   [source (w), destination (w), owner (signer, r)]
     *
     * @param int|string $amount Amount in base units (NOT decimals-adjusted).
     *        e.g. for 1.5 USDC (6 decimals) pass 1_500_000.
     * @param PublicKey|null $tokenProgramId Override for Token-2022. Defaults to SPL Token.
     */
    public static function transfer(
        PublicKey $source,
        PublicKey $destination,
        PublicKey $owner,
        $amount,
        ?PublicKey $tokenProgramId = null
    ): TransactionInstruction {
        $data = (new ByteBuffer())
            ->writeU8(self::IX_TRANSFER)
            ->writeU64($amount)
            ->toBytes();

        return new TransactionInstruction(
            $tokenProgramId ?? self::programId(),
            [
                AccountMeta::writable($source),
                AccountMeta::writable($destination),
                AccountMeta::signerReadonly($owner),
            ],
            $data
        );
    }

    /**
     * Transfer tokens with mint and decimals checks. PREFERRED.
     *
     * The on-chain program verifies that:
     *   - $source and $destination both reference $mint
     *   - $decimals matches $mint's actual decimals setting
     *
     * Either check failing aborts the transfer before any state change.
     * This is a much safer default for automated payment flows where the
     * client computes amounts off-chain.
     *
     * Account ordering:
     *   [source (w), mint (r), destination (w), owner (signer, r)]
     *
     * @param int|string $amount Amount in base units.
     * @param int $decimals The mint's decimals setting (0..255).
     */
    public static function transferChecked(
        PublicKey $source,
        PublicKey $mint,
        PublicKey $destination,
        PublicKey $owner,
        $amount,
        int $decimals,
        ?PublicKey $tokenProgramId = null
    ): TransactionInstruction {
        if ($decimals < 0 || $decimals > 255) {
            throw new InvalidArgumentException("decimals must be in 0..255, got {$decimals}");
        }
        $data = (new ByteBuffer())
            ->writeU8(self::IX_TRANSFER_CHECKED)
            ->writeU64($amount)
            ->writeU8($decimals)
            ->toBytes();

        return new TransactionInstruction(
            $tokenProgramId ?? self::programId(),
            [
                AccountMeta::writable($source),
                AccountMeta::readonly($mint),
                AccountMeta::writable($destination),
                AccountMeta::signerReadonly($owner),
            ],
            $data
        );
    }
}
