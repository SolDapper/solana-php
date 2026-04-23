<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Keypair;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\SolanaException;
use SolanaPhpSdk\Util\Base58;

/**
 * Immutable 32-byte Solana public key.
 *
 * Accepts multiple input formats:
 *   - Base58 string (the canonical human-readable form)
 *   - 32-byte binary string
 *   - Array of 32 unsigned byte integers
 *   - Another PublicKey instance (returns a clone)
 *
 * Provides Program Derived Address (PDA) derivation for on-chain account
 * addressing, which is critical for Associated Token Accounts and most
 * Anchor program interactions.
 */
final class PublicKey
{
    public const LENGTH = 32;

    /**
     * The "Program Derived Address" marker appended to seeds during PDA derivation.
     * Matches solana-sdk's PDA_MARKER constant exactly.
     */
    private const PDA_MARKER = 'ProgramDerivedAddress';

    private const MAX_SEED_LENGTH = 32;
    private const MAX_SEEDS = 16;

    /** @var string 32 raw bytes */
    private string $bytes;

    /**
     * @param string|array<int>|PublicKey $value
     */
    public function __construct($value)
    {
        if ($value instanceof self) {
            $this->bytes = $value->bytes;
            return;
        }

        if (is_array($value)) {
            if (count($value) !== self::LENGTH) {
                throw new InvalidArgumentException(
                    'PublicKey array must contain exactly ' . self::LENGTH . ' bytes, got ' . count($value)
                );
            }
            $bytes = '';
            foreach ($value as $i => $byte) {
                if (!is_int($byte) || $byte < 0 || $byte > 255) {
                    throw new InvalidArgumentException("Invalid byte at index {$i}: must be int 0-255");
                }
                $bytes .= chr($byte);
            }
            $this->bytes = $bytes;
            return;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                'PublicKey value must be a Base58 string, 32-byte binary string, or int array'
            );
        }

        // Disambiguation: a 32-byte raw pubkey can contain any byte value,
        // including bytes that happen to look like printable ASCII in the
        // Base58 alphabet. Conversely, a 32-character Base58 string (which
        // decodes to 32 bytes only when the input has 24+ leading zero
        // bytes) is also exactly 32 characters long. To resolve the overlap:
        //
        //   - If the string is entirely Base58 alphabet characters, prefer
        //     Base58 decoding. Base58-encoded pubkeys are ALWAYS valid
        //     Base58 by construction, while a raw 32-byte pubkey contains
        //     non-printable bytes with probability ~1 - (58/256)^32 ≈ 1.0.
        //
        //   - Only if the string contains bytes outside the Base58 alphabet
        //     (including most raw random bytes) do we treat it as raw.
        //
        // This matches @solana/web3.js behavior when a string is passed to
        // new PublicKey().
        if (self::isBase58String($value)) {
            $decoded = Base58::decode($value);
            if (strlen($decoded) !== self::LENGTH) {
                throw new InvalidArgumentException(
                    'Invalid PublicKey: Base58 string decoded to ' . strlen($decoded) .
                    ' bytes, expected ' . self::LENGTH
                );
            }
            $this->bytes = $decoded;
            return;
        }

        // 32-byte raw binary form (string contains non-Base58 bytes).
        if (strlen($value) === self::LENGTH) {
            $this->bytes = $value;
            return;
        }

        throw new InvalidArgumentException(
            'Invalid PublicKey: input is neither a valid Base58 string nor a 32-byte binary string'
        );
    }

    /**
     * True if every character is in the Base58 alphabet.
     */
    private static function isBase58String(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if (strpos(Base58::ALPHABET, $s[$i]) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create a PublicKey from a Base58-encoded string.
     */
    public static function fromBase58(string $base58): self
    {
        return new self($base58);
    }

    /**
     * Create a PublicKey from 32 raw bytes.
     */
    public static function fromBytes(string $bytes): self
    {
        if (strlen($bytes) !== self::LENGTH) {
            throw new InvalidArgumentException(
                'PublicKey bytes must be exactly ' . self::LENGTH . ' bytes'
            );
        }
        return new self($bytes);
    }

    /**
     * The all-zero public key. Used as the System Program's "no account" sentinel
     * and as the default fee payer in certain contexts.
     */
    public static function default(): self
    {
        return new self(str_repeat("\x00", self::LENGTH));
    }

    public function toBytes(): string
    {
        return $this->bytes;
    }

    public function toBase58(): string
    {
        return Base58::encode($this->bytes);
    }

    /**
     * @return array<int> 32 unsigned bytes
     */
    public function toArray(): array
    {
        $out = [];
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out[] = ord($this->bytes[$i]);
        }
        return $out;
    }

    public function equals(PublicKey $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }

    public function __toString(): string
    {
        return $this->toBase58();
    }

    // ----- PDA derivation -------------------------------------------------

    /**
     * Check whether a 32-byte value lies on the Ed25519 curve.
     *
     * PDAs are explicitly chosen to lie *off* the curve so they cannot
     * have corresponding private keys — this is what makes them program-controlled.
     *
     * Delegates to {@see Ed25519::isOnCurve} which implements RFC 8032 point
     * decompression and matches @solana/web3.js byte-for-byte on all tested
     * inputs. See the test suite for golden vectors.
     */
    public static function isOnCurve(string $bytes): bool
    {
        return \SolanaPhpSdk\Util\Ed25519::isOnCurve($bytes);
    }

    /**
     * Derive a program address from seeds and a program ID.
     *
     * This is the low-level primitive. Most callers want findProgramAddress()
     * instead, which handles the bump seed search automatically.
     *
     * @param array<string> $seeds Binary seed strings, each at most 32 bytes, max 16 seeds.
     * @throws InvalidArgumentException If seeds are malformed.
     * @throws SolanaException If the derived address is on-curve (invalid PDA).
     */
    public static function createProgramAddress(array $seeds, PublicKey $programId): self
    {
        if (count($seeds) > self::MAX_SEEDS) {
            throw new InvalidArgumentException(
                'Too many seeds (max ' . self::MAX_SEEDS . '): ' . count($seeds)
            );
        }

        $buffer = '';
        foreach ($seeds as $i => $seed) {
            if (!is_string($seed)) {
                throw new InvalidArgumentException("Seed at index {$i} must be a binary string");
            }
            if (strlen($seed) > self::MAX_SEED_LENGTH) {
                throw new InvalidArgumentException(
                    "Seed at index {$i} exceeds max length of " . self::MAX_SEED_LENGTH . ' bytes'
                );
            }
            $buffer .= $seed;
        }

        $buffer .= $programId->toBytes();
        $buffer .= self::PDA_MARKER;

        $hash = hash('sha256', $buffer, true);

        if (self::isOnCurve($hash)) {
            throw new SolanaException(
                'Derived address is on the Ed25519 curve (not a valid PDA). ' .
                'Use findProgramAddress to automatically find a valid bump.'
            );
        }

        return new self($hash);
    }

    /**
     * Find a valid PDA for the given seeds and program ID.
     *
     * Iterates bump seeds from 255 down to 0, returning the first off-curve
     * result along with the bump byte used. This matches the behavior of
     * solana-sdk's find_program_address.
     *
     * @param array<string> $seeds
     * @return array{0: PublicKey, 1: int} Tuple of [address, bump]
     * @throws SolanaException If no valid PDA can be found (astronomically unlikely).
     */
    public static function findProgramAddress(array $seeds, PublicKey $programId): array
    {
        for ($bump = 255; $bump >= 0; $bump--) {
            try {
                $seedsWithBump = $seeds;
                $seedsWithBump[] = chr($bump);
                $address = self::createProgramAddress($seedsWithBump, $programId);
                return [$address, $bump];
            } catch (SolanaException $e) {
                // On-curve result, try next bump.
                continue;
            }
        }

        throw new SolanaException('Unable to find a valid program-derived address');
    }
}
