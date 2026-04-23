<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Borsh\BorshType;
use SolanaPhpSdk\Exception\BorshException;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh enum: u8 variant index followed by the variant's data.
 *
 * Variants are declared in order; the variant's zero-based position becomes
 * its discriminant byte on the wire. A variant type of NullType represents a
 * unit variant (no associated data, like Rust's `Quit` with no fields).
 *
 * PHP native representation follows the borsh-js convention:
 *   ['variantName' => $innerValue]
 *
 * Deserialization produces the same shape. For unit variants, the inner
 * value is an empty array.
 *
 * Standard Borsh limits enums to 256 variants (u8 discriminant). The SDK
 * enforces this at construction time.
 */
final class EnumType extends AbstractPrimitive
{
    /** @var array<string, BorshType> */
    private array $variants;

    /** @var array<int, string> Variant name indexed by discriminant value. */
    private array $variantOrder;

    /**
     * @param array<string, BorshType> $variants Ordered map of variant name to type.
     */
    public function __construct(array $variants)
    {
        if ($variants === []) {
            throw new BorshException('Enum must have at least one variant');
        }
        if (count($variants) > 256) {
            throw new BorshException('Standard Borsh enums support at most 256 variants');
        }
        $order = [];
        foreach ($variants as $name => $type) {
            if (!is_string($name) || $name === '') {
                throw new BorshException('Enum variant names must be non-empty strings');
            }
            if (!$type instanceof BorshType) {
                throw new BorshException("Enum variant '{$name}' must be a BorshType instance");
            }
            $order[] = $name;
        }
        $this->variants = $variants;
        $this->variantOrder = $order;
    }

    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_array($value) || count($value) !== 1) {
            throw $this->fail(
                'Enum requires an array with exactly one entry [variantName => value]'
            );
        }
        $variantName = array_key_first($value);
        if (!is_string($variantName)) {
            throw $this->fail('Enum variant key must be a string');
        }
        if (!isset($this->variants[$variantName])) {
            throw $this->fail("Unknown enum variant: '{$variantName}'");
        }
        $discriminant = array_search($variantName, $this->variantOrder, true);
        $buffer->writeU8($discriminant);
        $this->variants[$variantName]->serialize($value[$variantName], $buffer);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $discriminant = $buffer->readU8();
        if (!isset($this->variantOrder[$discriminant])) {
            throw $this->fail("Unknown enum discriminant: {$discriminant}");
        }
        $name = $this->variantOrder[$discriminant];
        $inner = $this->variants[$name]->deserialize($buffer);
        return [$name => $inner];
    }
}
