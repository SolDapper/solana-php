<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Util\ByteBuffer;
use SolanaPhpSdk\Util\CompactU16;

/**
 * Compact-u16 ("short vec") encoding tests.
 *
 * The reference values come from the Rust `solana-short-vec` crate and from
 * observed web3.js transaction output. These encodings are baked into the
 * transaction wire format — a regression here would invalidate every
 * serialized transaction this SDK produces.
 */
final class CompactU16Test extends TestCase
{
    #[DataProvider('boundaryProvider')]
    public function testEncodeBoundaryValues(int $value, string $expectedHex): void
    {
        $this->assertSame($expectedHex, bin2hex(CompactU16::encode($value)));
    }

    #[DataProvider('boundaryProvider')]
    public function testDecodeBoundaryValues(int $expectedValue, string $hex): void
    {
        $buffer = ByteBuffer::fromBytes(hex2bin($hex));
        $this->assertSame($expectedValue, CompactU16::decode($buffer));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function boundaryProvider(): array
    {
        return [
            'zero'          => [0,     '00'],
            'one'           => [1,     '01'],
            'max_single'    => [0x7F,  '7f'],       // 127: largest 1-byte value
            'min_two_byte'  => [0x80,  '8001'],     // 128: smallest 2-byte value
            'u8_max'        => [0xFF,  'ff01'],     // 255
            'mid_two_byte'  => [0x100, '8002'],     // 256
            'max_two_byte'  => [0x3FFF, 'ff7f'],    // 16383: largest 2-byte value
            'min_three'     => [0x4000, '808001'],  // 16384: smallest 3-byte value
            'u16_max'       => [0xFFFF, 'ffff03'],  // 65535: largest possible
        ];
    }

    public function testEncodeRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CompactU16::encode(-1);
    }

    public function testEncodeRejectsAboveU16Max(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CompactU16::encode(0x10000);
    }

    public function testRoundTripRandomValues(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $v = random_int(0, 0xFFFF);
            $encoded = CompactU16::encode($v);
            $buffer = ByteBuffer::fromBytes($encoded);
            $decoded = CompactU16::decode($buffer);
            $this->assertSame($v, $decoded, "Round-trip failed for value {$v}");
            // The decoder must consume exactly the encoded bytes — no more, no less.
            $this->assertSame(0, $buffer->remaining(), "Decoder left bytes for value {$v}");
        }
    }

    public function testDecodeFailsOnTruncatedInput(): void
    {
        // Start a 2-byte encoding (continuation bit set) but don't provide the second byte.
        $this->expectException(InvalidArgumentException::class);
        $buffer = ByteBuffer::fromBytes("\x80");
        CompactU16::decode($buffer);
    }
}
