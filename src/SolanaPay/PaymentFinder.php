<?php

declare(strict_types=1);

namespace SolanaPhpSdk\SolanaPay;

use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\Commitment;
use SolanaPhpSdk\Rpc\RpcClient;

/**
 * Finds on-chain transactions that satisfy a Solana Pay transfer request.
 *
 * The standard ecommerce flow is:
 *
 *   1. Merchant creates a unique `reference` pubkey per order (random
 *      32-byte value — does NOT need a private key).
 *   2. Merchant builds a TransferRequest with that reference and shows
 *      the URL/QR code to the customer.
 *   3. Customer's wallet builds + signs + submits a transaction that
 *      includes the reference as a read-only account in its transfer
 *      instruction.
 *   4. Merchant polls or webhook-watches for transactions referencing
 *      that pubkey and verifies the transfer matches the expected
 *      amount, token, and recipient.
 *
 * This class handles step 4.
 *
 * Usage:
 *
 *   $finder = new PaymentFinder($rpcClient);
 *   $signature = $finder->findByReference($orderReferencePubkey);
 *   if ($signature !== null) {
 *       // Transaction found; now validate the transfer fields
 *       $ok = $finder->verifyTransfer(
 *           $signature,
 *           $expectedRecipient,
 *           $expectedAmount,
 *           $expectedMint
 *       );
 *   }
 *
 * Note: "signature" here means the transaction's Base58 signature, which
 * doubles as its transaction ID on Solana.
 *
 * IMPORTANT: verification is a partial implementation. findByReference()
 * is the production-ready piece; verifyTransfer() returns the raw
 * transaction data and leaves deeper amount/mint matching to higher-level
 * application code, because doing it correctly requires decoding the
 * specific transfer instruction the customer's wallet chose to use
 * (SystemProgram.Transfer vs. TokenProgram.Transfer vs.
 * TokenProgram.TransferChecked), which depends on the wallet.
 */
final class PaymentFinder
{
    private RpcClient $rpc;

    public function __construct(RpcClient $rpc)
    {
        $this->rpc = $rpc;
    }

    /**
     * Find the most recent transaction signature that touched the given
     * reference account.
     *
     * Uses getSignaturesForAddress under the hood. Returns null if no
     * transaction has referenced this account yet.
     *
     * @param int $limit Max number of signatures to request. The default
     *        (1) is typical: for a single-payment reference, only one
     *        transaction will ever exist.
     * @param string $commitment See {@see Commitment}.
     *
     * @return string|null Base58 transaction signature, or null if not found.
     */
    public function findByReference(
        PublicKey $reference,
        int $limit = 1,
        ?string $commitment = null
    ): ?string {
        $result = $this->rpc->call('getSignaturesForAddress', [
            $reference->toBase58(),
            [
                'limit' => $limit,
                'commitment' => $commitment ?? Commitment::CONFIRMED,
            ],
        ]);

        if (!is_array($result) || $result === []) {
            return null;
        }

        // Most recent first. Also reject any entry with an execution error.
        foreach ($result as $entry) {
            if (!is_array($entry) || empty($entry['signature'])) {
                continue;
            }
            if (!empty($entry['err'])) {
                // A failed transaction still gets logged by the RPC; skip it.
                continue;
            }
            return (string) $entry['signature'];
        }
        return null;
    }

    /**
     * Fetch the raw transaction body for a signature.
     *
     * Returns the decoded JSON as-is from the RPC — this includes the
     * message, account keys, and instruction list. Caller is responsible
     * for matching the specific transfer semantics (SOL vs. SPL token,
     * amount, recipient account, etc.) against expectations.
     *
     * Returns null if the transaction is not found (not yet confirmed,
     * or outside the RPC's retention window).
     *
     * @return array<string, mixed>|null
     */
    public function getTransaction(string $signature, ?string $commitment = null): ?array
    {
        $result = $this->rpc->call('getTransaction', [
            $signature,
            [
                'commitment' => $commitment ?? Commitment::CONFIRMED,
                'encoding' => 'json',
                'maxSupportedTransactionVersion' => 0,
            ],
        ]);
        return is_array($result) ? $result : null;
    }

    /**
     * Convenience: find a transaction by reference AND fetch it in one call.
     *
     * @return array<string, mixed>|null Raw transaction JSON or null if
     *         nothing has landed yet.
     */
    public function waitForPayment(
        PublicKey $reference,
        ?string $commitment = null
    ): ?array {
        $sig = $this->findByReference($reference, 1, $commitment);
        if ($sig === null) {
            return null;
        }
        $tx = $this->getTransaction($sig, $commitment);
        if ($tx === null) {
            return null;
        }
        // Return the signature alongside the tx payload for caller convenience.
        $tx['_signature'] = $sig;
        return $tx;
    }
}
