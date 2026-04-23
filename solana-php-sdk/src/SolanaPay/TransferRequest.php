<?php

declare(strict_types=1);

namespace SolanaPhpSdk\SolanaPay;

use SolanaPhpSdk\Exception\SolanaPayException;
use SolanaPhpSdk\Keypair\PublicKey;

/**
 * Typed representation of a Solana Pay transfer-request URL.
 *
 * A transfer request is a non-interactive payment link: the wallet reads
 * the URL, constructs the transaction itself, and prompts the user to
 * sign. This is the format used for QR-code checkout flows — the merchant
 * generates the URL server-side, encodes it in a QR code, and the customer's
 * wallet app handles everything else.
 *
 * Spec reference: https://docs.solanapay.com/spec#transfer-request
 *
 *   solana:<recipient>
 *     ?amount=<amount>
 *     &spl-token=<spl-token>
 *     &reference=<reference>
 *     &reference=<reference>   // multiple allowed
 *     &label=<label>
 *     &message=<message>
 *     &memo=<memo>
 *
 * Fields:
 *
 *   recipient  (required, PublicKey)
 *     Base58 pubkey of a NATIVE SOL account — NOT a token account.
 *     For SPL token payments, the wallet derives the recipient's ATA
 *     from (recipient, splToken).
 *
 *   amount     (optional, string-encoded decimal)
 *     In user units, NOT base units. 1.5 means 1.5 SOL (or 1.5 USDC at
 *     6 decimals → 1_500_000 base units). Omitting the amount makes the
 *     wallet prompt the user — useful for tip jars or donation links.
 *
 *   splToken   (optional, PublicKey)
 *     SPL Token mint address. If absent, the URL describes a SOL transfer.
 *
 *   references (optional, PublicKey[])
 *     Base58-encoded 32-byte values attached to the transfer instruction
 *     as readonly non-signer accounts. These become indexable identifiers
 *     searchable via getSignaturesForAddress — the standard way to
 *     correlate an on-chain payment with an off-chain order ID.
 *
 *   label, message, memo (optional, strings)
 *     Display metadata. `memo` additionally becomes an on-chain memo
 *     instruction emitted immediately before the transfer.
 *
 * Instances are constructed via the static factory methods or via
 * {@see Url::parse()}. All validation happens at construction time — if
 * you hold a TransferRequest, its fields are guaranteed spec-compliant.
 */
final class TransferRequest
{
    public PublicKey $recipient;
    public ?string $amount;             // Decimal string (e.g. "1.5"), or null if wallet should prompt.
    public ?PublicKey $splToken;

    /** @var array<int, PublicKey> */
    public array $references;

    public ?string $label;
    public ?string $message;
    public ?string $memo;

    /**
     * @param array<int, PublicKey> $references
     */
    public function __construct(
        PublicKey $recipient,
        ?string $amount = null,
        ?PublicKey $splToken = null,
        array $references = [],
        ?string $label = null,
        ?string $message = null,
        ?string $memo = null
    ) {
        if ($amount !== null) {
            self::validateAmount($amount);
        }
        foreach ($references as $i => $ref) {
            if (!$ref instanceof PublicKey) {
                throw new SolanaPayException("references[{$i}] must be a PublicKey");
            }
        }

        $this->recipient = $recipient;
        $this->amount = $amount;
        $this->splToken = $splToken;
        $this->references = array_values($references);
        $this->label = $label;
        $this->message = $message;
        $this->memo = $memo;
    }

    /**
     * Validate an amount string against the Solana Pay spec.
     *
     * Rules (from the spec):
     *   - Non-negative integer or decimal
     *   - Leading zero required if value < 1 (".5" is invalid, "0.5" is valid)
     *   - No scientific notation
     *   - In "user" units (not lamports / base units)
     *
     * The caller is responsible for mint-specific decimal-count validation
     * since that requires knowing the mint.
     */
    public static function validateAmount(string $amount): void
    {
        if ($amount === '') {
            throw new SolanaPayException('amount cannot be empty');
        }

        // Reject scientific notation explicitly.
        if (preg_match('/[eE]/', $amount) === 1) {
            throw new SolanaPayException("amount must not use scientific notation: '{$amount}'");
        }

        // Must match: integer part (at least one digit) optionally followed by .<digits>
        // Leading-zero-required-for-decimals rule: ".5" is rejected.
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $amount) !== 1) {
            throw new SolanaPayException("amount is not a valid non-negative decimal: '{$amount}'");
        }
    }

    /**
     * Validate that an amount's decimal precision doesn't exceed a mint's
     * decimals setting. Separate from amount-syntax validation because
     * this requires off-URL knowledge (the mint's decimals field).
     *
     * SOL's decimals = 9. Most stablecoins = 6. Some tokens = 0.
     */
    public static function validateAmountDecimals(string $amount, int $maxDecimals): void
    {
        if ($maxDecimals < 0) {
            throw new SolanaPayException('maxDecimals must be non-negative');
        }
        $dotPos = strpos($amount, '.');
        if ($dotPos === false) {
            return; // Integer amount — always valid.
        }
        $decimals = strlen($amount) - $dotPos - 1;
        if ($decimals > $maxDecimals) {
            throw new SolanaPayException(
                "amount has {$decimals} decimal places but the token supports at most {$maxDecimals}"
            );
        }
    }

    /**
     * Fluent helper so the common "build a URL for order N" pattern reads well.
     */
    public static function builder(PublicKey $recipient): TransferRequestBuilder
    {
        return new TransferRequestBuilder($recipient);
    }
}
