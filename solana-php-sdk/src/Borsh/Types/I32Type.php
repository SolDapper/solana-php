<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh i32: signed 32-bit integer (two's complement), little-endian.
 */
final class I32Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_int($value) || $value < -2147483648 || $value > 2147483647) {
            throw $this->fail('i32 out of range: ' . var_export($value, true));
        }
        $buffer->writeI32($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return $buffer->readI32();
    }
}
