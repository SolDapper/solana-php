<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Util;

use SolanaPhpSdk\Exception\InvalidArgumentException;

/**
 * Sequential byte buffer for binary serialization.
 *
 * Wraps PHP's pack()/unpack() with a cursor-based API that's far cleaner
 * for protocols like Borsh and Solana's wire format. All multi-byte
 * integers are little-endian (Solana / Borsh convention).
 *
 * Usage:
 *   $buf = new ByteBuffer();
 *   $buf->writeU32(42)->writeBytes($pubkey);
 *   $bytes = $buf->toBytes();
 *
 *   $reader = ByteBuffer::fromBytes($bytes);
 *   $n = $reader->readU32();
 *   $pk = $reader->readBytes(32);
 */
final class ByteBuffer
{
    private string $buffer;
    private int $position;

    public function __construct(string $initial = '')
    {
        $this->buffer = $initial;
        $this->position = 0;
    }

    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    public function toBytes(): string
    {
        return $this->buffer;
    }

    public function length(): int
    {
        return strlen($this->buffer);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function remaining(): int
    {
        return strlen($this->buffer) - $this->position;
    }

    public function seek(int $position): self
    {
        if ($position < 0 || $position > strlen($this->buffer)) {
            throw new InvalidArgumentException(
                "Seek position {$position} out of bounds (buffer length: " . strlen($this->buffer) . ')'
            );
        }
        $this->position = $position;
        return $this;
    }

    // ----- Writers --------------------------------------------------------

    public function writeBytes(string $bytes): self
    {
        $this->buffer .= $bytes;
        return $this;
    }

    public function writeU8(int $value): self
    {
        $this->assertUnsignedRange($value, 0, 0xFF, 'u8');
        $this->buffer .= chr($value);
        return $this;
    }

    public function writeU16(int $value): self
    {
        $this->assertUnsignedRange($value, 0, 0xFFFF, 'u16');
        $this->buffer .= pack('v', $value);
        return $this;
    }

    public function writeU32(int $value): self
    {
        $this->assertUnsignedRange($value, 0, 0xFFFFFFFF, 'u32');
        $this->buffer .= pack('V', $value);
        return $this;
    }

    /**
     * Write a u64 as little-endian bytes.
     *
     * Accepts either an int (on 64-bit platforms) or a numeric string for
     * values that exceed PHP_INT_MAX. Solana lamport amounts and token
     * balances routinely exceed 2^53, so strings are the safer default.
     */
    public function writeU64($value): self
    {
        $bytes = self::u64ToBytes($value);
        $this->buffer .= $bytes;
        return $this;
    }

    public function writeI8(int $value): self
    {
        $this->assertSignedRange($value, -128, 127, 'i8');
        $this->buffer .= pack('c', $value);
        return $this;
    }

    public function writeI16(int $value): self
    {
        $this->assertSignedRange($value, -32768, 32767, 'i16');
        // pack('v') is unsigned; manually convert two's complement
        $this->buffer .= pack('v', $value < 0 ? $value + 0x10000 : $value);
        return $this;
    }

    public function writeI32(int $value): self
    {
        $this->assertSignedRange($value, -2147483648, 2147483647, 'i32');
        $this->buffer .= pack('V', $value < 0 ? $value + 0x100000000 : $value);
        return $this;
    }

    public function writeBool(bool $value): self
    {
        $this->buffer .= $value ? "\x01" : "\x00";
        return $this;
    }

    // ----- Readers --------------------------------------------------------

    public function readBytes(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException("Cannot read negative length: {$length}");
        }
        if ($this->position + $length > strlen($this->buffer)) {
            throw new InvalidArgumentException(
                "Cannot read {$length} bytes at position {$this->position}: only " .
                $this->remaining() . ' bytes remaining'
            );
        }
        $bytes = substr($this->buffer, $this->position, $length);
        $this->position += $length;
        return $bytes;
    }

    public function readU8(): int
    {
        return ord($this->readBytes(1));
    }

    public function readU16(): int
    {
        return unpack('v', $this->readBytes(2))[1];
    }

    public function readU32(): int
    {
        return unpack('V', $this->readBytes(4))[1];
    }

    /**
     * Read a u64 little-endian value.
     *
     * Returns an int when the value fits in PHP_INT_MAX, otherwise a
     * numeric string. Callers handling large token amounts should treat
     * the return as numeric-string and use bcmath/gmp for arithmetic.
     *
     * @return int|string
     */
    public function readU64()
    {
        return self::bytesToU64($this->readBytes(8));
    }

    public function readI8(): int
    {
        $byte = $this->readU8();
        return $byte >= 0x80 ? $byte - 0x100 : $byte;
    }

    public function readI16(): int
    {
        $val = $this->readU16();
        return $val >= 0x8000 ? $val - 0x10000 : $val;
    }

    public function readI32(): int
    {
        $val = $this->readU32();
        return $val >= 0x80000000 ? $val - 0x100000000 : $val;
    }

    public function readBool(): bool
    {
        $byte = $this->readU8();
        if ($byte !== 0 && $byte !== 1) {
            throw new InvalidArgumentException("Invalid boolean byte: 0x" . dechex($byte));
        }
        return $byte === 1;
    }

    // ----- u64 helpers ----------------------------------------------------

    /**
     * Convert a u64 value (int or numeric string) to 8 little-endian bytes.
     *
     * @param int|string $value
     */
    public static function u64ToBytes($value): string
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException("u64 cannot be negative: {$value}");
            }
            // On 64-bit platforms, pack('P') handles full u64 range for non-negative ints.
            return pack('P', $value);
        }

        if (!is_string($value) || !preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException("u64 must be a non-negative int or numeric string");
        }

        // Arbitrary-precision path for strings that exceed PHP_INT_MAX.
        $bytes = '';
        $remaining = $value;

        if (extension_loaded('gmp')) {
            $num = gmp_init($remaining);
            $maxU64 = gmp_init('18446744073709551615');
            if (gmp_cmp($num, $maxU64) > 0) {
                throw new InvalidArgumentException("Value exceeds u64 max: {$value}");
            }
            for ($i = 0; $i < 8; $i++) {
                $bytes .= chr(gmp_intval(gmp_and($num, 0xFF)));
                $num = gmp_div_q($num, 256);
            }
            return $bytes;
        }

        if (extension_loaded('bcmath')) {
            if (bccomp($remaining, '18446744073709551615') > 0) {
                throw new InvalidArgumentException("Value exceeds u64 max: {$value}");
            }
            for ($i = 0; $i < 8; $i++) {
                $byte = (int) bcmod($remaining, '256');
                $bytes .= chr($byte);
                $remaining = bcdiv($remaining, '256', 0);
            }
            return $bytes;
        }

        throw new InvalidArgumentException(
            "Writing u64 string values requires GMP or BCMath extension"
        );
    }

    /**
     * Convert 8 little-endian bytes to a u64. Returns int if it fits,
     * otherwise a numeric string.
     *
     * @return int|string
     */
    public static function bytesToU64(string $bytes)
    {
        if (strlen($bytes) !== 8) {
            throw new InvalidArgumentException(
                'bytesToU64 requires exactly 8 bytes, got ' . strlen($bytes)
            );
        }

        // Fast path: check if high bit of top byte is set. If not, fits in PHP_INT_MAX on 64-bit.
        if (PHP_INT_SIZE === 8 && ord($bytes[7]) < 0x80) {
            return unpack('P', $bytes)[1];
        }

        // Values above PHP_INT_MAX — return as numeric string.
        if (extension_loaded('gmp')) {
            $num = gmp_init(0);
            for ($i = 7; $i >= 0; $i--) {
                $num = gmp_add(gmp_mul($num, 256), ord($bytes[$i]));
            }
            return gmp_strval($num);
        }

        if (extension_loaded('bcmath')) {
            $num = '0';
            for ($i = 7; $i >= 0; $i--) {
                $num = bcadd(bcmul($num, '256'), (string) ord($bytes[$i]));
            }
            return $num;
        }

        throw new InvalidArgumentException(
            'Reading u64 values above PHP_INT_MAX requires GMP or BCMath extension'
        );
    }

    // ----- Validation helpers ---------------------------------------------

    private function assertUnsignedRange(int $value, int $min, int $max, string $type): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(
                "Value {$value} out of range for {$type} ({$min} to {$max})"
            );
        }
    }

    private function assertSignedRange(int $value, int $min, int $max, string $type): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(
                "Value {$value} out of range for {$type} ({$min} to {$max})"
            );
        }
    }
}
