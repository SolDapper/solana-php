<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\SolanaException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Util\ByteBuffer;
use SolanaPhpSdk\Util\CompactU16;

/**
 * A signed (or unsigned) Solana transaction ready for wire transmission.
 *
 * A Transaction bundles:
 *   - A compiled {@see Message}
 *   - A set of 64-byte Ed25519 signatures, one per required signer
 *
 * Signatures are stored in the same order as the signer accounts in the
 * Message's accountKeys. Missing signatures are represented as 64 zero bytes
 * (the "null signature"), which is what RPC endpoints expect for partially
 * signed transactions awaiting additional signers.
 *
 * Typical lifecycle:
 *
 *   $tx = Transaction::new([$ix1, $ix2], $feePayer->getPublicKey(), $blockhash);
 *   $tx->sign($feePayer, ...$otherSigners);
 *   $wire = $tx->serialize();      // send via sendTransaction RPC
 *
 * For workflows where different signers live in different places (e.g. an
 * offline cold wallet for one signature and a hot wallet for another), use
 * {@see partialSign} to contribute signatures incrementally.
 */
final class Transaction implements SignedTransaction
{
    public const SIGNATURE_LENGTH = 64;

    public Message $message;

    /**
     * Signatures indexed by account position (0..numRequiredSignatures - 1).
     * Null signature slot = 64 zero bytes, used before/during partial signing.
     *
     * @var array<int, string>
     */
    public array $signatures;

    /**
     * @param array<int, string> $signatures
     */
    public function __construct(Message $message, array $signatures = [])
    {
        $this->message = $message;
        $numRequired = $message->numRequiredSignatures;

        // Normalize the signatures array: exactly $numRequired slots, each
        // either a valid 64-byte signature or the 64-byte null signature.
        $normalized = [];
        for ($i = 0; $i < $numRequired; $i++) {
            if (isset($signatures[$i])) {
                if (strlen($signatures[$i]) !== self::SIGNATURE_LENGTH) {
                    throw new InvalidArgumentException(
                        'Signature at index ' . $i . ' must be exactly 64 bytes'
                    );
                }
                $normalized[] = $signatures[$i];
            } else {
                $normalized[] = str_repeat("\x00", self::SIGNATURE_LENGTH);
            }
        }
        $this->signatures = $normalized;
    }

    /**
     * Convenience constructor: compile a new Message from instructions and
     * return a Transaction wrapping it. No signing is performed.
     *
     * @param array<int, TransactionInstruction> $instructions
     * @param string $recentBlockhash 32-byte blockhash or Base58 string.
     */
    public static function new(array $instructions, PublicKey $feePayer, string $recentBlockhash): self
    {
        return new self(Message::compile($instructions, $feePayer, $recentBlockhash));
    }

    /**
     * Sign the transaction's Message with one or more keypairs.
     *
     * All required signers must be supplied in a single call — this method
     * fully signs the transaction. For incremental signing use
     * {@see partialSign}.
     */
    public function sign(Keypair ...$signers): void
    {
        if (count($signers) === 0) {
            throw new InvalidArgumentException('sign() requires at least one signer');
        }

        $messageBytes = $this->message->serialize();
        $this->applySignatures($messageBytes, $signers, /* requireComplete */ true);
    }

    /**
     * Sign with a subset of required signers.
     *
     * Useful for multi-sig flows where signers are distributed. Each signer
     * fills its corresponding slot; unfilled slots remain null until another
     * partialSign call completes the set.
     */
    public function partialSign(Keypair ...$signers): void
    {
        if (count($signers) === 0) {
            throw new InvalidArgumentException('partialSign() requires at least one signer');
        }
        $messageBytes = $this->message->serialize();
        $this->applySignatures($messageBytes, $signers, /* requireComplete */ false);
    }

    /**
     * @param array<int, Keypair> $signers
     */
    private function applySignatures(string $messageBytes, array $signers, bool $requireComplete): void
    {
        // Locate each signer's slot by pubkey, then write the Ed25519 signature there.
        foreach ($signers as $signer) {
            $signerPk = $signer->getPublicKey();
            $slot = null;
            foreach ($this->message->accountKeys as $i => $accPk) {
                if ($i >= $this->message->numRequiredSignatures) {
                    break;
                }
                if ($accPk->equals($signerPk)) {
                    $slot = $i;
                    break;
                }
            }

            if ($slot === null) {
                throw new SolanaException(
                    'Signer ' . $signerPk->toBase58() . ' is not a required signer for this transaction'
                );
            }

            $this->signatures[$slot] = $signer->sign($messageBytes);
        }

        if ($requireComplete) {
            foreach ($this->signatures as $i => $sig) {
                if ($sig === str_repeat("\x00", self::SIGNATURE_LENGTH)) {
                    $missing = $this->message->accountKeys[$i]->toBase58();
                    throw new SolanaException(
                        "Missing signature for required signer at index {$i}: {$missing}"
                    );
                }
            }
        }
    }

    /**
     * Serialize the full Transaction to wire bytes.
     *
     * By default this requires all signatures to be present. Pass
     * verifySignatures = false to produce an incompletely-signed wire
     * representation (useful for relaying to another signer via
     * sendRawTransaction with skipPreflight).
     */
    public function serialize(bool $verifySignatures = true): string
    {
        if ($verifySignatures) {
            foreach ($this->signatures as $i => $sig) {
                if ($sig === str_repeat("\x00", self::SIGNATURE_LENGTH)) {
                    throw new SolanaException(
                        "Cannot serialize: missing signature at index {$i}. " .
                        'Pass false to serialize() if partial signing is intended.'
                    );
                }
            }
        }

        $buf = new ByteBuffer();
        $buf->writeBytes(CompactU16::encode(count($this->signatures)));
        foreach ($this->signatures as $sig) {
            $buf->writeBytes($sig);
        }
        $buf->writeBytes($this->message->serialize());
        return $buf->toBytes();
    }

    /**
     * Parse a serialized Transaction from wire bytes.
     */
    public static function deserialize(string $bytes): self
    {
        $buf = ByteBuffer::fromBytes($bytes);
        $sigCount = CompactU16::decode($buf);
        $signatures = [];
        for ($i = 0; $i < $sigCount; $i++) {
            $signatures[] = $buf->readBytes(self::SIGNATURE_LENGTH);
        }
        $messageBytes = $buf->readBytes($buf->remaining());
        $message = Message::deserialize($messageBytes);
        return new self($message, $signatures);
    }

    /**
     * The transaction's primary signature (index 0) serves as its on-chain ID.
     *
     * Returns null if the fee-payer hasn't signed yet.
     */
    public function getSignature(): ?string
    {
        if (!isset($this->signatures[0])) {
            return null;
        }
        $sig = $this->signatures[0];
        if ($sig === str_repeat("\x00", self::SIGNATURE_LENGTH)) {
            return null;
        }
        return $sig;
    }

    /**
     * Verify that every present signature is valid for the serialized message.
     *
     * Returns false if any slot is unsigned or signed invalidly.
     */
    public function verifySignatures(): bool
    {
        $messageBytes = $this->message->serialize();
        foreach ($this->signatures as $i => $sig) {
            if ($sig === str_repeat("\x00", self::SIGNATURE_LENGTH)) {
                return false;
            }
            $signerPk = $this->message->accountKeys[$i];
            if (!Keypair::verify($sig, $messageBytes, $signerPk)) {
                return false;
            }
        }
        return true;
    }
}
