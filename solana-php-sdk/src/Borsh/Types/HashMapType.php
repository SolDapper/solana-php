<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Borsh\BorshType;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh HashMap<K, V>: u32 entry count followed by sorted (key, value) pairs.
 *
 * Per the Borsh specification, entries must be emitted in sorted order to
 * preserve canonical encoding. Critically, the sort is by **logical key
 * value** (as the Rust `borsh-rs` reference does), NOT by serialized key
 * bytes — these can differ dramatically. For example,
 * `{1u32: a, 256u32: b, 2u32: c}` sorts as `1, 2, 256` by value but as
 * `256, 1, 2` if you compared serialized u32 little-endian bytes.
 *
 * Ecosystem note: this implementation matches `borsh-rs` (which is what
 * on-chain Solana programs use). It intentionally diverges from `borsh-js`,
 * which does not sort at all. If you cross-check output with borsh-js and
 * see a discrepancy, borsh-js is wrong per the spec.
 *
 * Supported key comparisons by default:
 *   - Strings: byte-wise on UTF-8 bytes (matches Rust String::cmp)
 *   - Integer types (int or numeric string): numeric value comparison
 *   - PublicKey: byte-wise on 32-byte representation (matches Rust [u8; 32])
 *   - bool: false < true
 *
 * For other key types, or if you need custom ordering, pass a comparator
 * to the constructor. The comparator receives native decoded PHP values.
 *
 * PHP native representation is a list of [key, value] pairs, since Borsh
 * HashMaps allow any type as key (not just strings/ints).
 */
final class HashMapType extends AbstractPrimitive
{
    private BorshType $keyType;
    private BorshType $valueType;

    /** @var callable(mixed, mixed): int */
    private $comparator;

    /**
     * @param callable(mixed, mixed): int|null $comparator Optional key
     *        comparator returning negative / 0 / positive like strcmp.
     *        Receives decoded PHP values, not serialized bytes.
     */
    public function __construct(BorshType $keyType, BorshType $valueType, ?callable $comparator = null)
    {
        $this->keyType = $keyType;
        $this->valueType = $valueType;
        $this->comparator = $comparator ?? self::defaultComparator();
    }

    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_array($value)) {
            throw $this->fail('HashMap requires a list of [key, value] pairs, got: ' . gettype($value));
        }

        foreach ($value as $i => $pair) {
            if (!is_array($pair) || count($pair) !== 2
                || !array_key_exists(0, $pair) || !array_key_exists(1, $pair)) {
                throw $this->fail("HashMap entry at index {$i} must be [key, value]");
            }
        }

        // Sort by logical key value, not by serialized bytes.
        $sorted = $value;
        $cmp = $this->comparator;
        usort($sorted, static fn($a, $b) => $cmp($a[0], $b[0]));

        $count = count($sorted);
        if ($count > 0xFFFFFFFF) {
            throw $this->fail('HashMap size exceeds u32 limit');
        }
        $buffer->writeU32($count);
        foreach ($sorted as [$k, $v]) {
            $this->keyType->serialize($k, $buffer);
            $this->valueType->serialize($v, $buffer);
        }
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $count = $buffer->readU32();
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $k = $this->keyType->deserialize($buffer);
            $v = $this->valueType->deserialize($buffer);
            $out[] = [$k, $v];
        }
        return $out;
    }

    /**
     * Default comparator covering the key types that actually appear in
     * Solana program data: strings, integers (int or numeric string),
     * PublicKey instances, and booleans.
     *
     * @return callable(mixed, mixed): int
     */
    public static function defaultComparator(): callable
    {
        return static function ($a, $b): int {
            // PublicKey: byte-wise compare of the 32-byte representation.
            if ($a instanceof PublicKey && $b instanceof PublicKey) {
                return strcmp($a->toBytes(), $b->toBytes());
            }

            // Booleans: false < true.
            if (is_bool($a) && is_bool($b)) {
                return ($a === $b) ? 0 : ($a ? 1 : -1);
            }

            // Numeric (int or numeric string). Compare as arbitrary-precision numbers
            // to handle u64/u128 values that exceed PHP_INT_MAX.
            $aNumeric = is_int($a) || (is_string($a) && preg_match('/^-?\d+$/', $a));
            $bNumeric = is_int($b) || (is_string($b) && preg_match('/^-?\d+$/', $b));
            if ($aNumeric && $bNumeric) {
                return gmp_cmp(gmp_init((string) $a), gmp_init((string) $b));
            }

            // Plain strings: byte-wise compare (matches Rust String::cmp).
            if (is_string($a) && is_string($b)) {
                return strcmp($a, $b);
            }

            throw new \SolanaPhpSdk\Exception\BorshException(
                'Default HashMap key comparator cannot compare values of type ' .
                gettype($a) . ' / ' . gettype($b) .
                '. Pass a custom comparator to HashMapType.'
            );
        };
    }
}
