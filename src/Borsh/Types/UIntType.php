<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Exception\BorshException;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh unsigned integer of arbitrary fixed width, little-endian.
 *
 * Used for u128 (16 bytes) and u256 (32 bytes), both of which appear in
 * various Solana program interfaces. Values are always exchanged as numeric
 * strings since they routinely exceed PHP_INT_MAX.
 */
final class UIntType extends AbstractPrimitive
{
    /** @var int Byte width of the encoded integer (1..32). */
    private int $byteLength;

    public function __construct(int $byteLength)
    {
        if ($byteLength < 1 || $byteLength > 32) {
            throw new BorshException("UIntType byte length must be 1..32, got {$byteLength}");
        }
        $this->byteLength = $byteLength;
    }

    public function serialize($value, ByteBuffer $buffer): void
    {
        $str = is_int($value) ? (string) $value : $value;
        if (!is_string($str) || !preg_match('/^\d+$/', $str)) {
            throw $this->fail(
                'unsigned ' . ($this->byteLength * 8) . '-bit int requires non-negative int or numeric string, got: '
                . var_export($value, true)
            );
        }
        $maxExclusive = gmp_pow(2, $this->byteLength * 8);
        $num = gmp_init($str);
        if (gmp_cmp($num, $maxExclusive) >= 0) {
            throw $this->fail("value {$str} exceeds " . ($this->byteLength * 8) . '-bit unsigned range');
        }

        $bytes = '';
        for ($i = 0; $i < $this->byteLength; $i++) {
            $bytes .= chr(gmp_intval(gmp_and($num, 0xFF)));
            $num = gmp_div_q($num, 256);
        }
        $buffer->writeBytes($bytes);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $bytes = $buffer->readBytes($this->byteLength);
        $num = gmp_init(0);
        for ($i = $this->byteLength - 1; $i >= 0; $i--) {
            $num = gmp_add(gmp_mul($num, 256), ord($bytes[$i]));
        }
        return gmp_strval($num);
    }
}
