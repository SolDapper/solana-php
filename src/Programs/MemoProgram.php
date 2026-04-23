<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Programs;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Transaction\AccountMeta;
use SolanaPhpSdk\Transaction\TransactionInstruction;

/**
 * Instruction builder for the SPL Memo program.
 *
 * The Memo program is a lightweight on-chain program that validates and
 * emits a UTF-8 string as a log entry, attaching it to the transaction.
 * It performs no state changes and has no accounts of its own — the memo
 * text IS the instruction data.
 *
 * For ecommerce / payment flows, memos are the standard way to attach
 * correlation IDs like order numbers or invoice references to on-chain
 * payments. The memo becomes searchable in explorers (Solscan, Solana
 * Explorer) and retrievable via the RPC transaction history.
 *
 * Two program IDs exist:
 *   - {@see self::PROGRAM_ID_V2}: the current version, required by Solana
 *     Pay and recommended for all new use. Supports signers.
 *   - {@see self::PROGRAM_ID_V1}: the older version. Still works on-chain
 *     but lacks signer verification. Avoid unless integrating with legacy
 *     systems that explicitly require it.
 *
 * Wire format:
 *   Instruction data = raw UTF-8 bytes of the memo text.
 *   No length prefix, no discriminator.
 *
 * Memo length is limited by the transaction's 1232-byte size budget (after
 * subtracting all the other instruction overhead). In practice ~500 bytes
 * is a safe upper bound for a memo accompanying other instructions.
 */
final class MemoProgram
{
    public const PROGRAM_ID_V2 = 'MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr';
    public const PROGRAM_ID_V1 = 'Memo1UhkJRfHyvLMcVucJwxXeuD728EqVDDwQDxFMNo';

    private function __construct()
    {
    }

    public static function programId(): PublicKey
    {
        return new PublicKey(self::PROGRAM_ID_V2);
    }

    /**
     * Emit a memo, optionally requiring the given accounts to sign.
     *
     * If $signers is non-empty, the memo is only considered valid if all
     * listed accounts have signed the transaction — this is how Solana Pay
     * uses memos to cryptographically prove association between a memo and
     * a particular payer wallet.
     *
     * @param string $memo UTF-8 text. Not length-prefixed; the entire
     *        $memo string becomes the instruction data.
     * @param array<int, PublicKey> $signers Optional signer accounts
     *        whose signatures must accompany the memo.
     */
    public static function create(string $memo, array $signers = []): TransactionInstruction
    {
        // Guard against accidental non-UTF-8 input that would cause on-chain
        // deserialization to fail.
        if (!mb_check_encoding($memo, 'UTF-8')) {
            throw new InvalidArgumentException('Memo must be valid UTF-8');
        }

        $accounts = [];
        foreach ($signers as $i => $signer) {
            if (!$signer instanceof PublicKey) {
                throw new InvalidArgumentException("signers[{$i}] must be a PublicKey");
            }
            $accounts[] = AccountMeta::signerReadonly($signer);
        }

        return new TransactionInstruction(self::programId(), $accounts, $memo);
    }
}
