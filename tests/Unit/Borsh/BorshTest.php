<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Borsh;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Borsh\Borsh;
use SolanaPhpSdk\Exception\BorshException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh serialization / deserialization tests.
 *
 * The testGoldenVectors test is the single most important assertion in this
 * suite: it locks in byte-for-byte parity with borsh-js, which is what
 * @solana/web3.js and the rest of the JavaScript Solana stack use. If a
 * vector fails, our output would not be interoperable with on-chain
 * programs or other ecosystem tooling.
 */
final class BorshTest extends TestCase
{
    /**
     * Encode and decode all borsh-js reference vectors and verify both
     * directions byte-for-byte.
     */
    public function testGoldenVectorsEncodeAndDecode(): void
    {
        $vectors = require __DIR__ . '/fixtures_borsh.php';
        $this->assertNotEmpty($vectors);

        foreach ($vectors as $v) {
            $schema = ($v['schema_fn'])();
            $encoded = Borsh::encode($schema, $v['value']);
            $this->assertSame(
                $v['hex'],
                bin2hex($encoded),
                "Encode mismatch for '{$v['name']}'"
            );

            $decoded = Borsh::decode($schema, $encoded);
            $expected = array_key_exists('decode_eq', $v) ? $v['decode_eq'] : $v['value'];
            $this->assertSame(
                $expected,
                $decoded,
                "Decode mismatch for '{$v['name']}'"
            );
        }
    }

    // ----- Primitive edge cases ------------------------------------------

    public function testU8OutOfRangeThrows(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::u8(), 256);
    }

    public function testU8NegativeThrows(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::u8(), -1);
    }

    public function testU8NonIntThrows(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::u8(), 'not an int');
    }

    public function testU64AcceptsIntAndString(): void
    {
        $fromInt = Borsh::encode(Borsh::u64(), 42);
        $fromStr = Borsh::encode(Borsh::u64(), '42');
        $this->assertSame($fromInt, $fromStr);
    }

    public function testU64AboveMaxThrows(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::u64(), '18446744073709551616');
    }

    public function testU128RoundTrip(): void
    {
        $schema = Borsh::u128();
        $values = [
            '0',
            '1',
            '18446744073709551616',                           // 2^64
            '340282366920938463463374607431768211455',        // 2^128 - 1 (max)
        ];
        foreach ($values as $v) {
            $encoded = Borsh::encode($schema, $v);
            $this->assertSame(16, strlen($encoded), "u128 must encode as 16 bytes");
            $this->assertSame($v, Borsh::decode($schema, $encoded));
        }
    }

    public function testU128MaxBytes(): void
    {
        // 2^128 - 1 encodes as 16 0xFF bytes.
        $bytes = Borsh::encode(Borsh::u128(), '340282366920938463463374607431768211455');
        $this->assertSame(str_repeat('ff', 16), bin2hex($bytes));
    }

    public function testU128AboveMaxThrows(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::u128(), '340282366920938463463374607431768211456'); // 2^128
    }

    public function testI64ExtremesRoundTrip(): void
    {
        $schema = Borsh::i64();
        // i64 range: -2^63 .. 2^63 - 1
        foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $v) {
            $encoded = Borsh::encode($schema, $v);
            $this->assertSame($v, Borsh::decode($schema, $encoded));
        }
    }

    public function testBoolRejectsNonBool(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::bool(), 1);
    }

    public function testBoolRejectsInvalidByteOnDecode(): void
    {
        $this->expectException(BorshException::class);
        Borsh::decode(Borsh::bool(), "\x02");
    }

    public function testStringUtf8ByteLength(): void
    {
        // The length prefix is the byte count, not codepoint count.
        // "€" is 3 UTF-8 bytes.
        $bytes = Borsh::encode(Borsh::string(), '€');
        $this->assertSame('03000000e282ac', bin2hex($bytes));
    }

    public function testStringNonStringThrows(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::string(), 42);
    }

    public function testF64NaNRejected(): void
    {
        $this->expectException(BorshException::class);
        $this->expectExceptionMessage('NaN');
        Borsh::encode(Borsh::f64(), NAN);
    }

    public function testF64RoundTrip(): void
    {
        $values = [0.0, 1.0, -1.5, 3.14159265358979, PHP_FLOAT_MAX, PHP_FLOAT_MIN];
        foreach ($values as $v) {
            $encoded = Borsh::encode(Borsh::f64(), $v);
            $this->assertSame(8, strlen($encoded));
            $this->assertSame($v, Borsh::decode(Borsh::f64(), $encoded));
        }
    }

    // ----- Composite types -----------------------------------------------

    public function testOptionNoneAndSome(): void
    {
        $schema = Borsh::option(Borsh::u64());
        $this->assertSame('00', bin2hex(Borsh::encode($schema, null)));
        $this->assertSame(null, Borsh::decode($schema, Borsh::encode($schema, null)));

        $encoded = Borsh::encode($schema, 12345);
        $this->assertSame(1, ord($encoded[0]));
        $this->assertSame(12345, Borsh::decode($schema, $encoded));
    }

    public function testOptionInvalidTagThrows(): void
    {
        $this->expectException(BorshException::class);
        Borsh::decode(Borsh::option(Borsh::u8()), "\x02\x00");
    }

    public function testVecEmpty(): void
    {
        $schema = Borsh::vec(Borsh::u32());
        $this->assertSame('00000000', bin2hex(Borsh::encode($schema, [])));
        $this->assertSame([], Borsh::decode($schema, "\x00\x00\x00\x00"));
    }

    public function testVecRejectsAssociativeArray(): void
    {
        $this->expectException(BorshException::class);
        Borsh::encode(Borsh::vec(Borsh::u8()), ['foo' => 1, 'bar' => 2]);
    }

    public function testVecRejectsSparseArray(): void
    {
        $this->expectException(BorshException::class);
        // Sparse: key 5 with no 1..4
        Borsh::encode(Borsh::vec(Borsh::u8()), [0 => 1, 5 => 2]);
    }

    public function testFixedArrayWrongLengthThrows(): void
    {
        $this->expectException(BorshException::class);
        $this->expectExceptionMessage('exactly 4 elements');
        Borsh::encode(Borsh::fixedArray(Borsh::u8(), 4), [1, 2, 3]);
    }

    public function testFixedArrayOf32PubkeyBytes(): void
    {
        // Fixed-size arrays of u8 are commonly used to encode raw pubkey bytes.
        $schema = Borsh::fixedArray(Borsh::u8(), 32);
        $bytes = range(0, 31);
        $encoded = Borsh::encode($schema, $bytes);
        $this->assertSame(32, strlen($encoded));
        $this->assertSame($bytes, Borsh::decode($schema, $encoded));
    }

    public function testStructMissingFieldThrows(): void
    {
        $schema = Borsh::struct([
            'a' => Borsh::u8(),
            'b' => Borsh::u32(),
        ]);
        $this->expectException(BorshException::class);
        $this->expectExceptionMessage("Missing required struct field: 'b'");
        Borsh::encode($schema, ['a' => 1]);
    }

    public function testStructEmpty(): void
    {
        $schema = Borsh::struct([]);
        $this->assertSame('', Borsh::encode($schema, []));
        $this->assertSame([], Borsh::decode($schema, ''));
    }

    public function testStructFieldOrderIsPreservedOnDecode(): void
    {
        // The struct schema declares 'b' before 'a'. Decoded output must
        // follow schema order, not alphabetical or any other order.
        $schema = Borsh::struct([
            'b' => Borsh::u8(),
            'a' => Borsh::u8(),
        ]);
        $encoded = Borsh::encode($schema, ['b' => 1, 'a' => 2]);
        $decoded = Borsh::decode($schema, $encoded);
        $this->assertSame(['b' => 1, 'a' => 2], $decoded);
        $this->assertSame(['b', 'a'], array_keys($decoded));
    }

    public function testEnumUnknownVariantThrows(): void
    {
        $schema = Borsh::enum([
            'a' => Borsh::unit(),
            'b' => Borsh::u32(),
        ]);
        $this->expectException(BorshException::class);
        Borsh::encode($schema, ['nonexistent' => 1]);
    }

    public function testEnumUnknownDiscriminantThrows(): void
    {
        $schema = Borsh::enum([
            'a' => Borsh::unit(),
            'b' => Borsh::unit(),
        ]);
        $this->expectException(BorshException::class);
        $this->expectExceptionMessage('Unknown enum discriminant');
        Borsh::decode($schema, "\x05");
    }

    public function testEnumRequiresSingleEntry(): void
    {
        $schema = Borsh::enum(['a' => Borsh::unit()]);
        $this->expectException(BorshException::class);
        Borsh::encode($schema, []);
    }

    public function testEnumEmptyVariantsRejected(): void
    {
        $this->expectException(BorshException::class);
        Borsh::enum([]);
    }

    public function testHashMapMatchesBorshRsStringKeys(): void
    {
        // Golden vector from `borsh-rs` 1.5: HashMap<String, u8> with zulu=1, alpha=2, bravo=3
        // Expected: entries sorted alphabetically by key value.
        $schema = Borsh::hashMap(Borsh::string(), Borsh::u8());
        $expected = '0300000005000000616c7068610205000000627261766f03040000007a756c7501';

        // Input in non-sorted order — implementation must sort before emitting.
        $input = [
            ['zulu', 1],
            ['alpha', 2],
            ['bravo', 3],
        ];

        $this->assertSame(
            $expected,
            bin2hex(Borsh::encode($schema, $input)),
            'HashMap<String, u8> must match borsh-rs byte-for-byte'
        );
    }

    public function testHashMapSortsByLogicalNumericValue(): void
    {
        // Golden vector from borsh-rs: HashMap<u32, u8> with 1=10, 256=20, 2=30.
        // Sorted by numeric key: 1, 2, 256 (NOT by serialized bytes, which
        // would give 256 [0x00010000] before 1 [0x01000000]).
        $schema = Borsh::hashMap(Borsh::u32(), Borsh::u8());
        $expected = '03000000010000000a020000001e0001000014';

        $input = [
            [1, 10],
            [256, 20],
            [2, 30],
        ];

        $this->assertSame(
            $expected,
            bin2hex(Borsh::encode($schema, $input)),
            'HashMap<u32, u8> must sort by numeric key value, not serialized bytes'
        );
    }

    public function testHashMapInputOrderIrrelevant(): void
    {
        // Same entries in different input orders must produce identical bytes.
        $schema = Borsh::hashMap(Borsh::string(), Borsh::u8());
        $e1 = Borsh::encode($schema, [['charlie', 3], ['alpha', 1], ['bravo', 2]]);
        $e2 = Borsh::encode($schema, [['alpha', 1], ['bravo', 2], ['charlie', 3]]);
        $e3 = Borsh::encode($schema, [['bravo', 2], ['charlie', 3], ['alpha', 1]]);
        $this->assertSame(bin2hex($e1), bin2hex($e2));
        $this->assertSame(bin2hex($e2), bin2hex($e3));
    }

    public function testHashMapEmpty(): void
    {
        $schema = Borsh::hashMap(Borsh::u8(), Borsh::u8());
        $this->assertSame('00000000', bin2hex(Borsh::encode($schema, [])));
        $this->assertSame([], Borsh::decode($schema, "\x00\x00\x00\x00"));
    }

    public function testHashMapWithPublicKeyKeys(): void
    {
        // Pubkey-keyed HashMaps appear in some Anchor program state layouts.
        // Sort order is byte-wise on the 32-byte pubkey (matches Rust [u8; 32]).
        $pk1 = new \SolanaPhpSdk\Keypair\PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');
        $pk2 = new \SolanaPhpSdk\Keypair\PublicKey('ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL');

        $schema = Borsh::hashMap(Borsh::publicKey(), Borsh::u64());
        $encoded1 = Borsh::encode($schema, [[$pk1, 100], [$pk2, 200]]);
        $encoded2 = Borsh::encode($schema, [[$pk2, 200], [$pk1, 100]]);

        $this->assertSame(bin2hex($encoded1), bin2hex($encoded2));
    }

    public function testHashMapCustomComparator(): void
    {
        // Custom comparator for descending numeric order.
        $schema = Borsh::hashMap(
            Borsh::u8(),
            Borsh::u8(),
            static fn($a, $b) => $b - $a
        );

        $encoded = Borsh::encode($schema, [[1, 10], [3, 30], [2, 20]]);
        $decoded = Borsh::decode($schema, $encoded);
        $this->assertSame([3, 2, 1], array_column($decoded, 0));
        $this->assertSame([30, 20, 10], array_column($decoded, 1));
    }

    public function testPublicKeyTypeEncodesAs32Bytes(): void
    {
        $pk = new PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');
        $encoded = Borsh::encode(Borsh::publicKey(), $pk);
        $this->assertSame(32, strlen($encoded));
        $this->assertSame($pk->toBytes(), $encoded);

        $decoded = Borsh::decode(Borsh::publicKey(), $encoded);
        $this->assertInstanceOf(PublicKey::class, $decoded);
        $this->assertTrue($pk->equals($decoded));
    }

    public function testPublicKeyTypeAcceptsBase58(): void
    {
        $encoded = Borsh::encode(Borsh::publicKey(), 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');
        $this->assertSame(32, strlen($encoded));
    }

    // ----- Realistic composite: a payment instruction-like struct --------

    public function testSplTokenTransferLikeStruct(): void
    {
        // Mimics the shape of the SPL Token Transfer instruction data layout.
        // Instruction discriminant (u8) + amount (u64).
        $schema = Borsh::struct([
            'discriminant' => Borsh::u8(),
            'amount'       => Borsh::u64(),
        ]);

        $data = ['discriminant' => 3, 'amount' => 1_500_000]; // 1.5 USDC at 6 decimals
        $encoded = Borsh::encode($schema, $data);

        // Expected: 0x03 followed by u64 1500000 little-endian
        // 1500000 = 0x16E360
        $this->assertSame('0360e3160000000000', bin2hex($encoded));

        $back = Borsh::decode($schema, $encoded);
        $this->assertSame($data, $back);
    }

    public function testAnchorLikeDiscriminatorPlusArgs(): void
    {
        // Anchor programs prepend an 8-byte discriminator to instruction args.
        // This tests that we can compose such a layout with nested structs.
        $discriminator = hex2bin('a1f4b2c8d9e0f3a7');
        $schema = Borsh::struct([
            'discriminator' => Borsh::fixedArray(Borsh::u8(), 8),
            'args'          => Borsh::struct([
                'amount'    => Borsh::u64(),
                'recipient' => Borsh::publicKey(),
            ]),
        ]);

        $value = [
            'discriminator' => array_map('ord', str_split($discriminator)),
            'args' => [
                'amount'    => 5_000_000,
                'recipient' => new PublicKey('ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL'),
            ],
        ];

        $encoded = Borsh::encode($schema, $value);
        // Total length: 8 (disc) + 8 (u64) + 32 (pubkey) = 48
        $this->assertSame(48, strlen($encoded));

        // First 8 bytes must be the discriminator verbatim.
        $this->assertSame(bin2hex($discriminator), bin2hex(substr($encoded, 0, 8)));

        $decoded = Borsh::decode($schema, $encoded);
        $this->assertSame($value['args']['amount'], $decoded['args']['amount']);
        $this->assertTrue($value['args']['recipient']->equals($decoded['args']['recipient']));
    }

    // ----- Buffer behavior ------------------------------------------------

    public function testDecodeIgnoresTrailingBytes(): void
    {
        // The facade's decode() doesn't enforce exact consumption —
        // callers that need that should use ByteBuffer directly.
        $value = Borsh::decode(Borsh::u8(), "\x42\xDE\xAD\xBE\xEF");
        $this->assertSame(0x42, $value);
    }

    public function testByteBufferRemainingAfterDecode(): void
    {
        $buf = ByteBuffer::fromBytes("\x42\xDE\xAD");
        $v = Borsh::u8()->deserialize($buf);
        $this->assertSame(0x42, $v);
        $this->assertSame(2, $buf->remaining());
    }

    public function testDecodeOnTruncatedInputThrows(): void
    {
        $this->expectException(\SolanaPhpSdk\Exception\SolanaException::class);
        // u64 expects 8 bytes, give it 4.
        Borsh::decode(Borsh::u64(), "\x01\x02\x03\x04");
    }
}
