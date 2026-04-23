<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Util\ByteBuffer;

final class ByteBufferTest extends TestCase
{
    public function testEmptyBuffer(): void
    {
        $buf = new ByteBuffer();
        $this->assertSame(0, $buf->length());
        $this->assertSame(0, $buf->position());
        $this->assertSame('', $buf->toBytes());
    }

    public function testWriteU8(): void
    {
        $buf = new ByteBuffer();
        $buf->writeU8(0)->writeU8(255)->writeU8(128);
        $this->assertSame("\x00\xff\x80", $buf->toBytes());
    }

    public function testWriteU8OutOfRangeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ByteBuffer())->writeU8(256);
    }

    public function testWriteU16LittleEndian(): void
    {
        $buf = new ByteBuffer();
        $buf->writeU16(0x1234);
        $this->assertSame("\x34\x12", $buf->toBytes());
    }

    public function testWriteU32LittleEndian(): void
    {
        $buf = new ByteBuffer();
        $buf->writeU32(0x12345678);
        $this->assertSame("\x78\x56\x34\x12", $buf->toBytes());
    }

    public function testWriteU64WithInt(): void
    {
        $buf = new ByteBuffer();
        $buf->writeU64(1);
        $this->assertSame("\x01\x00\x00\x00\x00\x00\x00\x00", $buf->toBytes());
    }

    public function testWriteU64WithLargeString(): void
    {
        // Max u64 value = 2^64 - 1 = 18446744073709551615
        $buf = new ByteBuffer();
        $buf->writeU64('18446744073709551615');
        $this->assertSame(str_repeat("\xff", 8), $buf->toBytes());
    }

    public function testWriteU64Zero(): void
    {
        $buf = new ByteBuffer();
        $buf->writeU64(0);
        $this->assertSame(str_repeat("\x00", 8), $buf->toBytes());
    }

    public function testWriteU64StringAboveMaxThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds u64 max');
        (new ByteBuffer())->writeU64('18446744073709551616'); // max + 1
    }

    public function testWriteU64NegativeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ByteBuffer())->writeU64(-1);
    }

    public function testWriteU64InvalidStringThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ByteBuffer())->writeU64('not-a-number');
    }

    public function testWriteSignedIntegers(): void
    {
        $buf = new ByteBuffer();
        $buf->writeI8(-1)->writeI16(-1)->writeI32(-1);
        $this->assertSame(
            "\xff"          // i8 -1
            . "\xff\xff"    // i16 -1 LE
            . "\xff\xff\xff\xff", // i32 -1 LE
            $buf->toBytes()
        );
    }

    public function testWriteBool(): void
    {
        $buf = new ByteBuffer();
        $buf->writeBool(true)->writeBool(false);
        $this->assertSame("\x01\x00", $buf->toBytes());
    }

    public function testWriteBytes(): void
    {
        $buf = new ByteBuffer();
        $buf->writeBytes('hello')->writeBytes('world');
        $this->assertSame('helloworld', $buf->toBytes());
    }

    // ----- Readers --------------------------------------------------------

    public function testReadPrimitivesRoundTrip(): void
    {
        $buf = new ByteBuffer();
        $buf->writeU8(42)
            ->writeU16(12345)
            ->writeU32(0xDEADBEEF)
            ->writeU64(1_000_000_000_000)
            ->writeI8(-42)
            ->writeI16(-12345)
            ->writeI32(-1)
            ->writeBool(true)
            ->writeBool(false);

        $reader = ByteBuffer::fromBytes($buf->toBytes());
        $this->assertSame(42, $reader->readU8());
        $this->assertSame(12345, $reader->readU16());
        $this->assertSame(0xDEADBEEF, $reader->readU32());
        $this->assertSame(1_000_000_000_000, $reader->readU64());
        $this->assertSame(-42, $reader->readI8());
        $this->assertSame(-12345, $reader->readI16());
        $this->assertSame(-1, $reader->readI32());
        $this->assertTrue($reader->readBool());
        $this->assertFalse($reader->readBool());
        $this->assertSame(0, $reader->remaining());
    }

    public function testReadU64LargeValueReturnsString(): void
    {
        // A value exceeding PHP_INT_MAX (2^63 - 1 on 64-bit) should return a numeric string.
        $buf = new ByteBuffer();
        $buf->writeU64('18446744073709551615');
        $reader = ByteBuffer::fromBytes($buf->toBytes());
        $result = $reader->readU64();
        $this->assertSame('18446744073709551615', (string) $result);
    }

    public function testReadBytes(): void
    {
        $buf = ByteBuffer::fromBytes('hello world');
        $this->assertSame('hello', $buf->readBytes(5));
        $this->assertSame(' ', $buf->readBytes(1));
        $this->assertSame('world', $buf->readBytes(5));
    }

    public function testReadBeyondBufferThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only');
        ByteBuffer::fromBytes('hi')->readBytes(10);
    }

    public function testReadInvalidBoolThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ByteBuffer::fromBytes("\x02")->readBool();
    }

    public function testSeek(): void
    {
        $buf = ByteBuffer::fromBytes('hello');
        $buf->readBytes(2);
        $this->assertSame(2, $buf->position());
        $buf->seek(0);
        $this->assertSame('hello', $buf->readBytes(5));
    }

    public function testSeekOutOfBoundsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ByteBuffer::fromBytes('abc')->seek(100);
    }

    public function testU64StaticHelpers(): void
    {
        // Test well-known lamport values
        $oneSol = 1_000_000_000; // 1 SOL in lamports
        $encoded = ByteBuffer::u64ToBytes($oneSol);
        $this->assertSame(8, strlen($encoded));
        $this->assertSame($oneSol, ByteBuffer::bytesToU64($encoded));
    }

    public function testBytesToU64InvalidLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ByteBuffer::bytesToU64('short');
    }
}
