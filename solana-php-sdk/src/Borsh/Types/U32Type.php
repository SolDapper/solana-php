<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh u32: unsigned 32-bit integer, little-endian.
 *
 * Also used as the length prefix for dynamic containers (Vec, String, HashMap).
 */
final class U32Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_int($value) || $value < 0 || $value > 0xFFFFFFFF) {
            throw $this->fail('u32 requires int in range 0..4294967295, got: ' . var_export($value, true));
        }
        $buffer->writeU32($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return $buffer->readU32();
    }
}
