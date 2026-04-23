<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Keypair;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\SolanaException;

/**
 * Ed25519 keypair for signing Solana transactions.
 *
 * Solana uses Ed25519 for all account signatures. This class wraps PHP's
 * libsodium (sodium_*) functions to provide generation, import, and signing.
 *
 * Secret key format (Solana convention):
 *   The "secret key" exposed here is 64 bytes: 32-byte seed + 32-byte public key.
 *   This matches the format used by solana-keygen, the JSON array format
 *   (["[64 numbers]"]) produced by the Solana CLI, and the JS web3.js SDK.
 *
 * Security note:
 *   Keypairs should never be logged, serialized to untrusted storage, or
 *   embedded in version control. In production, load secret keys from
 *   environment variables, HSMs, or dedicated key management systems.
 */
final class Keypair
{
    public const SECRET_KEY_LENGTH = 64;
    public const SEED_LENGTH = 32;

    private string $secretKey;
    private PublicKey $publicKey;

    private function __construct(string $secretKey, PublicKey $publicKey)
    {
        $this->secretKey = $secretKey;
        $this->publicKey = $publicKey;
    }

    public function __destruct()
    {
        // Best-effort secret zeroing. PHP's memory model doesn't guarantee this
        // fully scrubs the key from all memory locations, but it reduces the
        // window where the secret sits readable in heap.
        if (function_exists('sodium_memzero')) {
            try {
                sodium_memzero($this->secretKey);
            } catch (\SodiumException $e) {
                // Already zeroed or unavailable — nothing to do.
            }
        }
    }

    /**
     * Generate a new random keypair using libsodium's CSPRNG.
     */
    public static function generate(): self
    {
        $keypair = sodium_crypto_sign_keypair();
        return self::fromSodiumKeypair($keypair);
    }

    /**
     * Create a keypair from a 32-byte seed.
     *
     * The same seed always produces the same keypair, making this suitable
     * for deterministic key derivation (e.g. from a BIP39 mnemonic).
     */
    public static function fromSeed(string $seed): self
    {
        if (strlen($seed) !== self::SEED_LENGTH) {
            throw new InvalidArgumentException(
                'Seed must be exactly ' . self::SEED_LENGTH . ' bytes, got ' . strlen($seed)
            );
        }
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        return self::fromSodiumKeypair($keypair);
    }

    /**
     * Import a keypair from a 64-byte secret key (Solana CLI / web3.js format).
     */
    public static function fromSecretKey(string $secretKey): self
    {
        if (strlen($secretKey) !== self::SECRET_KEY_LENGTH) {
            throw new InvalidArgumentException(
                'Secret key must be exactly ' . self::SECRET_KEY_LENGTH . ' bytes, got ' . strlen($secretKey)
            );
        }

        // The 64-byte Solana secret key is actually the libsodium secret key format:
        // 32-byte seed followed by 32-byte public key. libsodium's sign functions
        // consume this exact layout directly.
        $publicKeyBytes = substr($secretKey, 32, 32);

        return new self($secretKey, PublicKey::fromBytes($publicKeyBytes));
    }

    /**
     * Import a keypair from the JSON array format produced by `solana-keygen`.
     *
     * The Solana CLI stores keys as a JSON array of 64 integers, e.g.
     * `[174, 47, 154, ..., 32]`. This is the canonical on-disk format.
     *
     * @param string|array<int> $json Either a JSON string or pre-decoded array.
     */
    public static function fromJsonArray($json): self
    {
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Invalid JSON keypair: must decode to an array');
            }
            $json = $decoded;
        }

        if (!is_array($json) || count($json) !== self::SECRET_KEY_LENGTH) {
            throw new InvalidArgumentException(
                'JSON keypair must contain exactly ' . self::SECRET_KEY_LENGTH . ' integers'
            );
        }

        $bytes = '';
        foreach ($json as $i => $b) {
            if (!is_int($b) || $b < 0 || $b > 255) {
                throw new InvalidArgumentException("Invalid byte at index {$i}: must be int 0-255");
            }
            $bytes .= chr($b);
        }

        return self::fromSecretKey($bytes);
    }

    private static function fromSodiumKeypair(string $sodiumKeypair): self
    {
        $secretKey = sodium_crypto_sign_secretkey($sodiumKeypair);
        $publicKeyBytes = sodium_crypto_sign_publickey($sodiumKeypair);
        return new self($secretKey, PublicKey::fromBytes($publicKeyBytes));
    }

    public function getPublicKey(): PublicKey
    {
        return $this->publicKey;
    }

    /**
     * Export the 64-byte secret key. Handle with care.
     */
    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    /**
     * Export the keypair as a JSON array (Solana CLI format).
     *
     * @return array<int>
     */
    public function toJsonArray(): array
    {
        $out = [];
        for ($i = 0; $i < self::SECRET_KEY_LENGTH; $i++) {
            $out[] = ord($this->secretKey[$i]);
        }
        return $out;
    }

    /**
     * Sign a message with this keypair's private key.
     *
     * Produces a 64-byte detached Ed25519 signature. Solana transactions use
     * detached signatures (the signature is stored separately from the message),
     * not the combined sign format.
     */
    public function sign(string $message): string
    {
        try {
            return sodium_crypto_sign_detached($message, $this->secretKey);
        } catch (\SodiumException $e) {
            throw new SolanaException('Failed to sign message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify a detached signature against a message and public key.
     *
     * Static utility for verifying any signature, not just those from this keypair.
     */
    public static function verify(string $signature, string $message, PublicKey $publicKey): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey->toBytes());
        } catch (\SodiumException $e) {
            return false;
        }
    }
}
