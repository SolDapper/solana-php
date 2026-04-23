<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh unit type `()`: occupies zero bytes on the wire.
 *
 * Primary use is as the type of unit enum variants — variants like Rust's
 *   enum Message { Quit, ... }
 * where Quit carries no associated data. The serialize method ignores its
 * input (conventionally an empty array) and writes nothing; deserialize
 * returns an empty array.
 */
final class UnitType extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        // Writes nothing, regardless of input. An empty array is conventional.
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return [];
    }
}
