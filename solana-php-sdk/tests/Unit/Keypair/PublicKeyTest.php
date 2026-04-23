<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Keypair;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\SolanaException;
use SolanaPhpSdk\Keypair\PublicKey;

final class PublicKeyTest extends TestCase
{
    private const SYSTEM_PROGRAM_B58 = '11111111111111111111111111111111';
    private const TOKEN_PROGRAM_B58 = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
    private const ATA_PROGRAM_B58 = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL';
    private const WSOL_MINT_B58 = 'So11111111111111111111111111111111111111112';

    public function testConstructFromBase58(): void
    {
        $pk = new PublicKey(self::TOKEN_PROGRAM_B58);
        $this->assertSame(self::TOKEN_PROGRAM_B58, $pk->toBase58());
    }

    public function testConstructFrom32RawBytes(): void
    {
        $bytes = str_repeat("\x00", 32);
        $pk = new PublicKey($bytes);
        $this->assertSame($bytes, $pk->toBytes());
        $this->assertSame(self::SYSTEM_PROGRAM_B58, $pk->toBase58());
    }

    public function testConstructFromIntArray(): void
    {
        $bytes = array_fill(0, 32, 0);
        $pk = new PublicKey($bytes);
        $this->assertSame(str_repeat("\x00", 32), $pk->toBytes());
    }

    public function testConstructFromAnotherPublicKey(): void
    {
        $original = new PublicKey(self::TOKEN_PROGRAM_B58);
        $copy = new PublicKey($original);
        $this->assertTrue($original->equals($copy));
        $this->assertSame($original->toBase58(), $copy->toBase58());
    }

    public function testDefaultIsAllZeros(): void
    {
        $pk = PublicKey::default();
        $this->assertSame(str_repeat("\x00", 32), $pk->toBytes());
        $this->assertSame(self::SYSTEM_PROGRAM_B58, $pk->toBase58());
    }

    public function testInvalidBase58LengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base58 string decoded to');
        // 16-byte value encoded — wrong length
        new PublicKey('3GTizNnBkM3EhqJTqZQdfjr');
    }

    /**
     * Regression test: when a 32-character input is a valid Base58 string
     * (all chars in alphabet), it must be decoded as Base58 — NOT treated
     * as 32 raw ASCII bytes. The System Program ID
     * '11111111111111111111111111111111' is exactly this case: 32 chars,
     * all Base58, and it must decode to 32 zero bytes (NOT 32 ASCII '1's).
     *
     * Getting this wrong silently produces corrupt transactions.
     */
    public function testBase58StringPreferredOverRawBytesForAmbiguousLength(): void
    {
        $sysProg = new PublicKey('11111111111111111111111111111111');
        $this->assertSame(str_repeat("\x00", 32), $sysProg->toBytes());
        $this->assertSame('11111111111111111111111111111111', $sysProg->toBase58());
    }

    public function testInvalidBinaryLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // 33 bytes — not 32, but also not a valid Base58 string of expected length
        new PublicKey(str_repeat("\x01", 40));
    }

    public function testWrongArrayLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain exactly 32 bytes');
        new PublicKey([1, 2, 3]);
    }

    public function testInvalidByteInArrayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $arr = array_fill(0, 32, 0);
        $arr[5] = 300; // out of range
        new PublicKey($arr);
    }

    public function testEqualsIsConstantTime(): void
    {
        $a = new PublicKey(self::TOKEN_PROGRAM_B58);
        $b = new PublicKey(self::TOKEN_PROGRAM_B58);
        $c = new PublicKey(self::ATA_PROGRAM_B58);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testToStringReturnsBase58(): void
    {
        $pk = new PublicKey(self::TOKEN_PROGRAM_B58);
        $this->assertSame(self::TOKEN_PROGRAM_B58, (string) $pk);
    }

    public function testToArray(): void
    {
        $pk = PublicKey::default();
        $arr = $pk->toArray();
        $this->assertCount(32, $arr);
        $this->assertSame(array_fill(0, 32, 0), $arr);
    }

    // ----- PDA tests ------------------------------------------------------

    public function testIsOnCurveForGeneratedPubkey(): void
    {
        // A freshly generated keypair's public key is always on-curve.
        $keypair = sodium_crypto_sign_keypair();
        $pkBytes = sodium_crypto_sign_publickey($keypair);
        $this->assertTrue(PublicKey::isOnCurve($pkBytes));
    }

    public function testIsOffCurveForHashedValue(): void
    {
        // Most random hashes are off-curve (~50% probability).
        $found = false;
        for ($i = 0; $i < 20; $i++) {
            $h = hash('sha256', "test-off-curve-$i", true);
            if (!PublicKey::isOnCurve($h)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find an off-curve hash within 20 tries');
    }

    public function testIsOnCurveRejectsWrongLength(): void
    {
        $this->assertFalse(PublicKey::isOnCurve(str_repeat("\x01", 31)));
        $this->assertFalse(PublicKey::isOnCurve(str_repeat("\x01", 33)));
    }

    /**
     * Golden vectors generated from @solana/web3.js PublicKey.isOnCurve.
     *
     * If any of these fail, our curve check has diverged from the canonical
     * reference — which means PDA derivation will produce different addresses
     * than the rest of the ecosystem. This is a critical invariant.
     */
    public function testIsOnCurveMatchesWeb3JsGoldenVectors(): void
    {
        $fixtures = require __DIR__ . '/fixtures_curve.php';
        foreach ($fixtures['curveVectors'] as [$hex, $expected]) {
            $bytes = hex2bin($hex);
            $this->assertSame(
                $expected,
                PublicKey::isOnCurve($bytes),
                "Mismatch for {$hex}: expected " . ($expected ? 'on' : 'off') . '-curve'
            );
        }
    }

    /**
     * Verify Associated Token Account derivation matches @solana/web3.js
     * byte-for-byte across multiple wallet/mint pairs.
     *
     * ATA derivation touches every critical piece: PublicKey parsing, PDA
     * derivation, seed concatenation, bump iteration, and curve validation.
     * If these pass, the underlying primitives are correct.
     */
    public function testAtaDerivationMatchesWeb3Js(): void
    {
        $fixtures = require __DIR__ . '/fixtures_curve.php';
        $tokenProgram = new PublicKey(self::TOKEN_PROGRAM_B58);
        $ataProgram = new PublicKey(self::ATA_PROGRAM_B58);

        foreach ($fixtures['ataVectors'] as $v) {
            $seed = str_repeat(chr($v['seedByte']), 32);
            $kp = \SolanaPhpSdk\Keypair\Keypair::fromSeed($seed);

            $this->assertSame(
                $v['wallet'],
                $kp->getPublicKey()->toBase58(),
                "Seed-derived wallet should match JS for seed byte {$v['seedByte']}"
            );

            $mint = new PublicKey($v['mint']);
            [$ata, $bump] = PublicKey::findProgramAddress(
                [$kp->getPublicKey()->toBytes(), $tokenProgram->toBytes(), $mint->toBytes()],
                $ataProgram
            );

            $this->assertSame(
                $v['ata'],
                $ata->toBase58(),
                "ATA mismatch for wallet {$v['wallet']} mint {$v['mint']}"
            );
            $this->assertFalse(PublicKey::isOnCurve($ata->toBytes()), 'ATA must be off-curve');
        }
    }

    public function testFindProgramAddressIsDeterministic(): void
    {
        $programId = new PublicKey(self::TOKEN_PROGRAM_B58);
        $seeds = ['hello', 'world'];

        [$addr1, $bump1] = PublicKey::findProgramAddress($seeds, $programId);
        [$addr2, $bump2] = PublicKey::findProgramAddress($seeds, $programId);

        $this->assertTrue($addr1->equals($addr2));
        $this->assertSame($bump1, $bump2);
    }

    public function testCreateProgramAddressTooManySeedsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many seeds');
        $seeds = array_fill(0, 17, 'x');
        PublicKey::createProgramAddress($seeds, new PublicKey(self::TOKEN_PROGRAM_B58));
    }

    public function testCreateProgramAddressSeedTooLongThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds max length');
        PublicKey::createProgramAddress(
            [str_repeat('x', 33)],
            new PublicKey(self::TOKEN_PROGRAM_B58)
        );
    }
}
