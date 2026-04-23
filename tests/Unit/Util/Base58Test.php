<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Util\Base58;

/**
 * Base58 tests against known vectors.
 *
 * These vectors come from two sources:
 *   - The Bitcoin Base58 test vectors (industry standard)
 *   - Known Solana public keys (verifying Solana-specific compatibility)
 *
 * Both GMP and BCMath backends are tested independently to ensure parity.
 */
final class Base58Test extends TestCase
{
    protected function tearDown(): void
    {
        // Reset backend so tests don't leak state.
        Base58::setBackend(null);
    }

    /**
     * @dataProvider knownVectorProvider
     */
    public function testEncodeWithGmp(string $hex, string $expectedBase58): void
    {
        if (!extension_loaded('gmp')) {
            $this->markTestSkipped('GMP extension not loaded');
        }
        Base58::setBackend('gmp');
        $this->assertSame($expectedBase58, Base58::encode(hex2bin($hex)));
    }

    /**
     * @dataProvider knownVectorProvider
     */
    public function testDecodeWithGmp(string $hex, string $base58): void
    {
        if (!extension_loaded('gmp')) {
            $this->markTestSkipped('GMP extension not loaded');
        }
        Base58::setBackend('gmp');
        $this->assertSame($hex, bin2hex(Base58::decode($base58)));
    }

    /**
     * @dataProvider knownVectorProvider
     */
    public function testEncodeWithBcmath(string $hex, string $expectedBase58): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('BCMath extension not loaded');
        }
        Base58::setBackend('bcmath');
        $this->assertSame($expectedBase58, Base58::encode(hex2bin($hex)));
    }

    /**
     * @dataProvider knownVectorProvider
     */
    public function testDecodeWithBcmath(string $hex, string $base58): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('BCMath extension not loaded');
        }
        Base58::setBackend('bcmath');
        $this->assertSame($hex, bin2hex(Base58::decode($base58)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function knownVectorProvider(): array
    {
        return [
            // Bitcoin Base58 standard test vectors
            'empty' => ['', ''],
            'single zero byte' => ['00', '1'],
            'two zero bytes' => ['0000', '11'],
            'hello world ascii' => ['68656c6c6f20776f726c64', 'StV1DL6CwTryKyV'],
            'leading zero preserved' => ['0068656c6c6f20776f726c64', '1StV1DL6CwTryKyV'],

            // 32-byte all-zero pubkey — the Solana "default" / System Program as all zeros
            '32 zero bytes' => [
                str_repeat('00', 32),
                '11111111111111111111111111111111',
            ],

            // System Program ID: 11111111111111111111111111111111 decodes to 32 zero bytes
            // (This is why the System Program is at the "default" address.)

            // Token Program ID: TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA
            'token program id' => [
                '06ddf6e1d765a193d9cbe146ceeb79ac1cb485ed5f5b37913a8cf5857eff00a9',
                'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
            ],

            // Associated Token Program: ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL
            'associated token program id' => [
                '8c97258f4e2489f1bb3d1029148e0d830b5a1399daff1084048e7bd8dbe9f859',
                'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL',
            ],
        ];
    }

    public function testRoundTripRandomBytes(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $bytes = random_bytes(random_int(1, 64));
            $encoded = Base58::encode($bytes);
            $decoded = Base58::decode($encoded);
            $this->assertSame($bytes, $decoded, 'Round-trip failed for random bytes');
        }
    }

    public function testDecodeInvalidCharacterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Base58 character');
        // '0' is not in the Base58 alphabet (confusable with 'O')
        Base58::decode('abc0def');
    }

    public function testDecodeInvalidCharacterThrowsForL(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // 'l' is not in the Base58 alphabet (confusable with '1' and 'I')
        Base58::decode('abclefg');
    }

    public function testGmpAndBcmathProduceIdenticalResults(): void
    {
        if (!extension_loaded('gmp') || !extension_loaded('bcmath')) {
            $this->markTestSkipped('Both GMP and BCMath required for parity test');
        }

        for ($i = 0; $i < 10; $i++) {
            $bytes = random_bytes(32);

            Base58::setBackend('gmp');
            $gmpEncoded = Base58::encode($bytes);

            Base58::setBackend('bcmath');
            $bcmathEncoded = Base58::encode($bytes);

            $this->assertSame(
                $gmpEncoded,
                $bcmathEncoded,
                'GMP and BCMath produced different Base58 output for ' . bin2hex($bytes)
            );
        }
    }
}
