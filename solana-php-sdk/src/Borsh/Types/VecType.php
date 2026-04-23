<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Borsh\BorshType;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh Vec<T>: u32 length prefix followed by N elements.
 *
 * PHP arrays are used as the native representation. The array must be a list
 * (sequential integer keys starting from 0) — associative arrays will be
 * rejected since they have no natural Borsh mapping.
 */
final class VecType extends AbstractPrimitive
{
    private BorshType $inner;

    public function __construct(BorshType $inner)
    {
        $this->inner = $inner;
    }

    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_array($value)) {
            throw $this->fail('Vec requires an array, got: ' . gettype($value));
        }
        // Verify this is a list, not an associative array.
        $expected = 0;
        foreach ($value as $k => $_) {
            if ($k !== $expected) {
                throw $this->fail('Vec requires a list (sequential integer keys from 0)');
            }
            $expected++;
        }

        $count = count($value);
        if ($count > 0xFFFFFFFF) {
            throw $this->fail('Vec length exceeds u32 limit');
        }
        $buffer->writeU32($count);
        foreach ($value as $item) {
            $this->inner->serialize($item, $buffer);
        }
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $count = $buffer->readU32();
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->inner->deserialize($buffer);
        }
        return $out;
    }
}
