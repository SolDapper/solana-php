<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh f32: IEEE 754 single-precision, little-endian.
 *
 * The Borsh spec explicitly forbids NaN to preserve bijective encoding;
 * this implementation rejects it on serialize.
 */
final class F32Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_float($value) && !is_int($value)) {
            throw $this->fail('f32 requires a number, got: ' . gettype($value));
        }
        $f = (float) $value;
        if (is_nan($f)) {
            throw $this->fail('f32 cannot encode NaN (Borsh spec forbids it)');
        }
        // pack('g') — 32-bit IEEE 754 little-endian
        $buffer->writeBytes(pack('g', $f));
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return unpack('g', $buffer->readBytes(4))[1];
    }
}
