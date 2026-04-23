<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh i8: signed 8-bit integer (two's complement).
 */
final class I8Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_int($value) || $value < -128 || $value > 127) {
            throw $this->fail('i8 requires int in -128..127, got: ' . var_export($value, true));
        }
        $buffer->writeI8($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return $buffer->readI8();
    }
}
