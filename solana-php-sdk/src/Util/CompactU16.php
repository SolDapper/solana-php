<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Util;

use SolanaPhpSdk\Exception\InvalidArgumentException;

/**
 * Compact-u16 (a.k.a. "short vec") length encoding.
 *
 * Solana's transaction wire format uses a custom variable-length integer
 * encoding for array lengths. This predates the Solana ecosystem's adoption
 * of Borsh and is not Borsh-compatible — you cannot substitute u32 here.
 *
 * Algorithm (matches solana-sdk short_vec.rs):
 *   - Values 0..=127 (0x7f) encode as 1 byte.
 *   - Values 128..=16383 encode as 2 bytes: the low 7 bits of each byte carry
 *     data, and the high bit of the first byte is set to indicate
 *     continuation.
 *   - Values 16384..=65535 encode as 3 bytes: the first two bytes follow the
 *     same continuation pattern, and the third byte uses all 8 bits for the
 *     top portion of the value.
 *
 * The maximum representable value is 0xFFFF (65535) — same as u16.
 */
final class CompactU16
{
    public const MAX_VALUE = 0xFFFF;

    /**
     * Encode an integer in 1..3 bytes.
     */
    public static function encode(int $value): string
    {
        if ($value < 0 || $value > self::MAX_VALUE) {
            throw new InvalidArgumentException(
                "compact-u16 value {$value} out of range (0..65535)"
            );
        }

        $remaining = $value;
        $bytes = '';

        // Emit up to 3 bytes. First two bytes use 7 data bits + 1 continuation bit;
        // third byte (if reached) uses all 8 bits.
        for ($i = 0; $i < 3; $i++) {
            if ($i === 2) {
                // Third byte: take remaining bits as-is. At this point remaining
                // must already fit in 8 bits (since original value <= 0xFFFF and
                // we've consumed 14 bits in bytes 0 and 1).
                $bytes .= chr($remaining & 0xFF);
                return $bytes;
            }

            $lowSeven = $remaining & 0x7F;
            $remaining >>= 7;

            if ($remaining === 0) {
                // No more bytes needed — emit without continuation bit.
                $bytes .= chr($lowSeven);
                return $bytes;
            }

            // More bytes to come — set continuation bit.
            $bytes .= chr($lowSeven | 0x80);
        }

        // Unreachable: the loop always returns by iteration 3.
        return $bytes; // @codeCoverageIgnore
    }

    /**
     * Decode a compact-u16 starting at the buffer's current position,
     * advancing the cursor past the consumed bytes.
     *
     * @return int The decoded value.
     */
    public static function decode(ByteBuffer $buffer): int
    {
        $value = 0;

        for ($i = 0; $i < 3; $i++) {
            if ($buffer->remaining() < 1) {
                throw new InvalidArgumentException(
                    'Unexpected end of buffer while decoding compact-u16'
                );
            }
            $byte = $buffer->readU8();

            if ($i === 2) {
                // Third byte: all 8 bits are data.
                $value |= $byte << 14;
                if ($value > self::MAX_VALUE) {
                    throw new InvalidArgumentException(
                        'compact-u16 decoded value exceeds u16 range'
                    );
                }
                return $value;
            }

            $value |= ($byte & 0x7F) << ($i * 7);

            if (($byte & 0x80) === 0) {
                // No continuation — done.
                return $value;
            }
        }

        // Unreachable under the loop structure but keeps type checker happy.
        return $value; // @codeCoverageIgnore
    }

    /**
     * Decode a compact-u16 at the given offset in a raw string buffer.
     *
     * Returns [$value, $bytesConsumed] so the caller can advance its own
     * offset. Useful when the surrounding deserialization code is managing
     * an explicit offset (e.g. in MessageV0/VersionedTransaction) rather
     * than wrapping the whole payload in a ByteBuffer.
     *
     * @return array{0: int, 1: int}
     */
    public static function decodeAt(string $buffer, int $offset): array
    {
        $value = 0;
        $len = strlen($buffer);

        for ($i = 0; $i < 3; $i++) {
            if ($offset + $i >= $len) {
                throw new InvalidArgumentException(
                    'Unexpected end of buffer while decoding compact-u16'
                );
            }
            $byte = ord($buffer[$offset + $i]);

            if ($i === 2) {
                $value |= $byte << 14;
                if ($value > self::MAX_VALUE) {
                    throw new InvalidArgumentException(
                        'compact-u16 decoded value exceeds u16 range'
                    );
                }
                return [$value, 3];
            }

            $value |= ($byte & 0x7F) << ($i * 7);

            if (($byte & 0x80) === 0) {
                return [$value, $i + 1];
            }
        }

        return [$value, 3]; // @codeCoverageIgnore
    }
}
