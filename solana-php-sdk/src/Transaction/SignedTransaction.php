<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

/**
 * Common surface shared by legacy {@see Transaction} and {@see VersionedTransaction}.
 *
 * Exists so that RpcClient methods like sendTransaction / simulateTransaction
 * can accept either kind without callers needing to know which format they
 * have in hand. The interface is deliberately tiny — just the one thing
 * every signed transaction can do: produce its wire bytes.
 */
interface SignedTransaction
{
    /**
     * Serialize this transaction to raw wire bytes for submission to an RPC.
     *
     * @param bool $verifySignatures If true, reject transactions that aren't
     *        fully signed. Set to false only when exporting a partially-signed
     *        transaction for out-of-band signing.
     */
    public function serialize(bool $verifySignatures = true): string;
}
