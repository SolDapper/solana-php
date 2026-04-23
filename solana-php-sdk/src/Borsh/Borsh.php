<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh;

use SolanaPhpSdk\Borsh\Types\BoolType;
use SolanaPhpSdk\Borsh\Types\EnumType;
use SolanaPhpSdk\Borsh\Types\F32Type;
use SolanaPhpSdk\Borsh\Types\F64Type;
use SolanaPhpSdk\Borsh\Types\FixedArrayType;
use SolanaPhpSdk\Borsh\Types\HashMapType;
use SolanaPhpSdk\Borsh\Types\I16Type;
use SolanaPhpSdk\Borsh\Types\I32Type;
use SolanaPhpSdk\Borsh\Types\I64Type;
use SolanaPhpSdk\Borsh\Types\I8Type;
use SolanaPhpSdk\Borsh\Types\OptionType;
use SolanaPhpSdk\Borsh\Types\PublicKeyType;
use SolanaPhpSdk\Borsh\Types\StringType;
use SolanaPhpSdk\Borsh\Types\StructType;
use SolanaPhpSdk\Borsh\Types\U16Type;
use SolanaPhpSdk\Borsh\Types\U32Type;
use SolanaPhpSdk\Borsh\Types\U64Type;
use SolanaPhpSdk\Borsh\Types\U8Type;
use SolanaPhpSdk\Borsh\Types\UIntType;
use SolanaPhpSdk\Borsh\Types\UnitType;
use SolanaPhpSdk\Borsh\Types\VecType;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Fluent facade for Borsh schema construction and ad-hoc encode/decode.
 *
 * The type constructor methods (u8(), struct(), vec(), etc.) return BorshType
 * instances that can be composed freely. Example:
 *
 *   $schema = Borsh::struct([
 *       'instruction' => Borsh::u8(),
 *       'amount'      => Borsh::u64(),
 *       'memo'        => Borsh::option(Borsh::string()),
 *   ]);
 *   $bytes = Borsh::encode($schema, ['instruction' => 3, 'amount' => '1000000', 'memo' => 'hi']);
 *   $back  = Borsh::decode($schema, $bytes);
 *
 * Each call returns a fresh type instance; instances are immutable and safe
 * to share across encode/decode calls.
 */
final class Borsh
{
    // ----- Primitive constructors ----------------------------------------

    public static function u8(): BorshType   { return new U8Type(); }
    public static function u16(): BorshType  { return new U16Type(); }
    public static function u32(): BorshType  { return new U32Type(); }
    public static function u64(): BorshType  { return new U64Type(); }
    public static function u128(): BorshType { return new UIntType(16); }
    public static function u256(): BorshType { return new UIntType(32); }

    public static function i8(): BorshType   { return new I8Type(); }
    public static function i16(): BorshType  { return new I16Type(); }
    public static function i32(): BorshType  { return new I32Type(); }
    public static function i64(): BorshType  { return new I64Type(); }

    public static function f32(): BorshType  { return new F32Type(); }
    public static function f64(): BorshType  { return new F64Type(); }

    public static function bool(): BorshType   { return new BoolType(); }
    public static function string(): BorshType { return new StringType(); }
    public static function unit(): BorshType   { return new UnitType(); }

    public static function publicKey(): BorshType { return new PublicKeyType(); }

    // ----- Composite constructors ----------------------------------------

    /**
     * Option<T>: tag byte + optional inner value.
     */
    public static function option(BorshType $inner): BorshType
    {
        return new OptionType($inner);
    }

    /**
     * Vec<T>: u32-prefixed dynamic array.
     */
    public static function vec(BorshType $inner): BorshType
    {
        return new VecType($inner);
    }

    /**
     * [T; N]: fixed-size array without length prefix.
     */
    public static function fixedArray(BorshType $inner, int $length): BorshType
    {
        return new FixedArrayType($inner, $length);
    }

    /**
     * Ordered struct. Fields are emitted in the array's insertion order.
     *
     * @param array<string, BorshType> $fields
     */
    public static function struct(array $fields): BorshType
    {
        return new StructType($fields);
    }

    /**
     * Tagged enum. Variants are mapped to u8 discriminants based on their
     * insertion order. Use Borsh::unit() for variants with no associated data.
     *
     * @param array<string, BorshType> $variants
     */
    public static function enum(array $variants): BorshType
    {
        return new EnumType($variants);
    }

    /**
     * HashMap<K, V>: u32 count + logical-key-ordered pairs.
     *
     * Sorting by logical key value matches `borsh-rs` (what Solana on-chain
     * programs use). Pass a $comparator if your key type needs custom
     * ordering; otherwise the default comparator handles strings, integers,
     * PublicKey, and bool.
     *
     * @param callable(mixed, mixed): int|null $comparator
     */
    public static function hashMap(BorshType $keyType, BorshType $valueType, ?callable $comparator = null): BorshType
    {
        return new HashMapType($keyType, $valueType, $comparator);
    }

    // ----- Convenience encode/decode -------------------------------------

    /**
     * Encode a value using the given schema, returning raw bytes.
     */
    public static function encode(BorshType $schema, $value): string
    {
        $buffer = new ByteBuffer();
        $schema->serialize($value, $buffer);
        return $buffer->toBytes();
    }

    /**
     * Decode raw bytes into a native value using the given schema.
     *
     * Extra bytes past the end of the schema are ignored. Callers that need
     * to enforce exact consumption should instantiate a ByteBuffer directly
     * and inspect remaining() after deserialize().
     */
    public static function decode(BorshType $schema, string $bytes)
    {
        $buffer = ByteBuffer::fromBytes($bytes);
        return $schema->deserialize($buffer);
    }
}
