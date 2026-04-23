<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh i16: signed 16-bit integer (two's complement), little-endian.
 */
final class I16Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_int($value) || $value < -32768 || $value > 32767) {
            throw $this->fail('i16 out of range: ' . var_export($value, true));
        }
        $buffer->writeI16($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return $buffer->readI16();
    }
}
