<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh f64: IEEE 754 double-precision, little-endian.
 *
 * Rejects NaN on serialize per the Borsh spec.
 */
final class F64Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_float($value) && !is_int($value)) {
            throw $this->fail('f64 requires a number, got: ' . gettype($value));
        }
        $f = (float) $value;
        if (is_nan($f)) {
            throw $this->fail('f64 cannot encode NaN (Borsh spec forbids it)');
        }
        // pack('e') — 64-bit IEEE 754 little-endian
        $buffer->writeBytes(pack('e', $f));
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return unpack('e', $buffer->readBytes(8))[1];
    }
}
