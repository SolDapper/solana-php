<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh u16: unsigned 16-bit integer, little-endian.
 */
final class U16Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_int($value) || $value < 0 || $value > 0xFFFF) {
            throw $this->fail('u16 requires int in range 0..65535, got: ' . var_export($value, true));
        }
        $buffer->writeU16($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return $buffer->readU16();
    }
}
