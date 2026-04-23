<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Util\CompactU16;

/**
 * A signed (or partially-signed) versioned (v0) transaction.
 *
 * Shares the same outer envelope as the legacy {@see Transaction}:
 *
 *     [compact-u16 numSignatures]
 *     [signature (64 bytes)] * N
 *     [message bytes]
 *
 * The difference is that the message bytes begin with 0x80 (the version-0
 * prefix) rather than a u8 numRequiredSignatures.
 *
 * Use this class when you need Address Lookup Tables or any other v0-only
 * feature. For simple payment transactions that fit comfortably in legacy
 * form (which covers virtually all ecommerce-style payment flows), the
 * existing {@see Transaction} class is simpler and has equivalent
 * capabilities.
 *
 * Typical workflow:
 *
 *     $msg = MessageV0::compile($payer, $instructions, $blockhash, [$alt]);
 *     $tx  = new VersionedTransaction($msg);
 *     $tx->sign($payerKeypair);
 *     $wire = $tx->serialize();
 *     $signature = $rpcClient->sendRawTransaction($wire);
 */
final class VersionedTransaction implements SignedTransaction
{
    public MessageV0 $message;

    /** @var array<int, string> Raw 64-byte signatures indexed by signer position. */
    public array $signatures;

    public function __construct(MessageV0 $message, ?array $signatures = null)
    {
        $this->message = $message;
        $n = $message->numRequiredSignatures;
        if ($signatures === null) {
            // Initialize with all-zero (unsigned) placeholders.
            $this->signatures = array_fill(0, $n, str_repeat("\x00", 64));
        } else {
            if (count($signatures) !== $n) {
                throw new InvalidArgumentException(
                    "Expected {$n} signatures, got " . count($signatures)
                );
            }
            foreach ($signatures as $i => $sig) {
                if (!is_string($sig) || strlen($sig) !== 64) {
                    throw new InvalidArgumentException("signatures[{$i}] must be 64 raw bytes");
                }
            }
            $this->signatures = array_values($signatures);
        }
    }

    /**
     * Sign with one or more keypairs. Updates the signatures array in-place
     * at the position matching each keypair's pubkey in the message's
     * static account keys.
     */
    public function sign(Keypair ...$signers): void
    {
        if ($signers === []) {
            throw new InvalidArgumentException('At least one signer required');
        }
        $messageBytes = $this->message->serialize();
        foreach ($signers as $kp) {
            $idx = $this->signerIndex($kp->getPublicKey());
            if ($idx === null) {
                throw new InvalidArgumentException(
                    "Keypair {$kp->getPublicKey()->toBase58()} is not a required signer"
                );
            }
            $this->signatures[$idx] = sodium_crypto_sign_detached($messageBytes, $kp->getSecretKey());
        }
    }

    /**
     * Partial sign: sign with some signers without requiring all to be
     * present. Useful for multi-sig flows where signatures are collected
     * separately.
     */
    public function partialSign(Keypair ...$signers): void
    {
        // For v0 there's no distinction from sign() — both just update
        // matching positions. The name is kept for API parity with legacy
        // Transaction and to make intent clear at call sites.
        $this->sign(...$signers);
    }

    /**
     * Verify all required signatures match the message bytes.
     *
     * Returns true only if every position has a valid signature.
     * Unsigned (all-zero) placeholders count as invalid.
     */
    public function verifySignatures(): bool
    {
        $messageBytes = $this->message->serialize();
        $zeros = str_repeat("\x00", 64);
        foreach ($this->signatures as $i => $sig) {
            if ($sig === $zeros) {
                return false;
            }
            $signerKey = $this->message->staticAccountKeys[$i] ?? null;
            if ($signerKey === null) {
                return false;
            }
            if (!sodium_crypto_sign_verify_detached($sig, $messageBytes, $signerKey->toBytes())) {
                return false;
            }
        }
        return true;
    }

    /**
     * Serialize the full signed transaction for network transmission.
     *
     * @param bool $verifySignatures If true, refuse to serialize a transaction
     *        with any missing or invalid signatures. Set to false when
     *        exporting a partially-signed transaction for another signer.
     */
    public function serialize(bool $verifySignatures = true): string
    {
        if ($verifySignatures && !$this->verifySignatures()) {
            throw new InvalidArgumentException(
                'Cannot serialize: one or more signatures are missing or invalid'
            );
        }
        $out = CompactU16::encode(count($this->signatures));
        foreach ($this->signatures as $sig) {
            $out .= $sig;
        }
        $out .= $this->message->serialize();
        return $out;
    }

    /**
     * Deserialize a signed versioned transaction from wire bytes.
     *
     * This method ONLY accepts v0 messages. To deserialize either format
     * transparently, use {@see self::peekVersion()} to dispatch first.
     */
    public static function deserialize(string $wire): self
    {
        if ($wire === '') {
            throw new InvalidArgumentException('Cannot deserialize empty bytes');
        }
        [$numSigs, $consumed] = CompactU16::decodeAt($wire, 0);
        $offset = $consumed;
        if (strlen($wire) < $offset + $numSigs * 64) {
            throw new InvalidArgumentException('Transaction truncated: signatures');
        }
        $signatures = [];
        for ($i = 0; $i < $numSigs; $i++) {
            $signatures[] = substr($wire, $offset, 64);
            $offset += 64;
        }
        $messageBytes = substr($wire, $offset);
        $message = MessageV0::deserialize($messageBytes);
        return new self($message, $signatures);
    }

    /**
     * Inspect wire bytes and return the transaction version without fully
     * deserializing. Returns 'legacy' for messages with the high bit unset,
     * or the integer version number (currently only 0) for versioned
     * messages.
     *
     * Useful for routing incoming wire bytes to the right class
     * (legacy {@see Transaction} vs. this VersionedTransaction).
     *
     * @return int|string
     */
    public static function peekVersion(string $wire)
    {
        if ($wire === '') {
            throw new InvalidArgumentException('Cannot inspect empty bytes');
        }
        // Skip the signature count and signatures, look at first byte of message.
        [$numSigs, $consumed] = CompactU16::decodeAt($wire, 0);
        $messageStart = $consumed + $numSigs * 64;
        if (strlen($wire) < $messageStart + 1) {
            throw new InvalidArgumentException('Transaction truncated before message');
        }
        $firstByte = ord($wire[$messageStart]);
        if (($firstByte & 0x80) === 0) {
            return 'legacy';
        }
        return $firstByte & MessageV0::VERSION_PREFIX_MASK;
    }

    /**
     * Find the position of a pubkey in the message's static account keys,
     * returning null if absent. Only static positions can be signers
     * (signers can NEVER come from ALTs).
     */
    private function signerIndex(PublicKey $pk): ?int
    {
        $n = $this->message->numRequiredSignatures;
        for ($i = 0; $i < $n; $i++) {
            if ($this->message->staticAccountKeys[$i]->equals($pk)) {
                return $i;
            }
        }
        return null;
    }
}
