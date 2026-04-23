<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh i64: signed 64-bit integer (two's complement), little-endian.
 *
 * Accepts int or numeric string (the latter covers 32-bit PHP builds and
 * explicit string-based arithmetic). Decoded values are returned as int on
 * 64-bit PHP (which covers the entire i64 range) or as numeric string on
 * 32-bit builds if they overflow.
 */
final class I64Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (is_int($value)) {
            $num = gmp_init($value);
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $num = gmp_init($value);
        } else {
            throw $this->fail('i64 requires int or numeric string, got: ' . var_export($value, true));
        }

        $min = gmp_neg(gmp_pow(2, 63));
        $maxExclusive = gmp_pow(2, 63);
        if (gmp_cmp($num, $min) < 0 || gmp_cmp($num, $maxExclusive) >= 0) {
            throw $this->fail('i64 out of range: ' . gmp_strval($num));
        }

        // Convert to unsigned two's-complement representation.
        if (gmp_cmp($num, 0) < 0) {
            $num = gmp_add($num, gmp_pow(2, 64));
        }

        $bytes = '';
        for ($i = 0; $i < 8; $i++) {
            $bytes .= chr(gmp_intval(gmp_and($num, 0xFF)));
            $num = gmp_div_q($num, 256);
        }
        $buffer->writeBytes($bytes);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $bytes = $buffer->readBytes(8);
        $num = gmp_init(0);
        for ($i = 7; $i >= 0; $i--) {
            $num = gmp_add(gmp_mul($num, 256), ord($bytes[$i]));
        }
        // If the top bit is set, this represents a negative value.
        if (gmp_cmp($num, gmp_pow(2, 63)) >= 0) {
            $num = gmp_sub($num, gmp_pow(2, 64));
        }

        // On 64-bit PHP, every i64 value fits in PHP_INT_MAX, so return int.
        if (PHP_INT_SIZE === 8) {
            return gmp_intval($num);
        }
        return gmp_strval($num);
    }
}
