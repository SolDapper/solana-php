<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh u8: single unsigned byte.
 */
final class U8Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_int($value) || $value < 0 || $value > 0xFF) {
            throw $this->fail('u8 requires int in range 0..255, got: ' . var_export($value, true));
        }
        $buffer->writeU8($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return $buffer->readU8();
    }
}
