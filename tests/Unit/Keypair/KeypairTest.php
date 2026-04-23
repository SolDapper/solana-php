<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Keypair;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;

final class KeypairTest extends TestCase
{
    public function testGenerateProducesValidKeypair(): void
    {
        $kp = Keypair::generate();
        $this->assertSame(Keypair::SECRET_KEY_LENGTH, strlen($kp->getSecretKey()));
        $this->assertSame(PublicKey::LENGTH, strlen($kp->getPublicKey()->toBytes()));
    }

    public function testFromSeedIsDeterministic(): void
    {
        $seed = str_repeat("\x42", 32);
        $kp1 = Keypair::fromSeed($seed);
        $kp2 = Keypair::fromSeed($seed);
        $this->assertTrue($kp1->getPublicKey()->equals($kp2->getPublicKey()));
        $this->assertSame($kp1->getSecretKey(), $kp2->getSecretKey());
    }

    public function testFromSeedKnownVector(): void
    {
        // Seed of all 0x01 bytes — deterministic test vector.
        // Ed25519 public key for seed = 0x01 * 32 can be computed once and locked in.
        $seed = str_repeat("\x01", 32);
        $kp = Keypair::fromSeed($seed);

        // Compute expected via libsodium directly as reference.
        $expected = sodium_crypto_sign_seed_keypair($seed);
        $expectedPk = sodium_crypto_sign_publickey($expected);

        $this->assertSame(
            bin2hex($expectedPk),
            bin2hex($kp->getPublicKey()->toBytes()),
            'Seed-derived public key must match libsodium reference'
        );
    }

    public function testInvalidSeedLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed must be exactly 32 bytes');
        Keypair::fromSeed(str_repeat("\x00", 31));
    }

    public function testFromSecretKeyRoundTrip(): void
    {
        $original = Keypair::generate();
        $imported = Keypair::fromSecretKey($original->getSecretKey());

        $this->assertTrue($original->getPublicKey()->equals($imported->getPublicKey()));
        $this->assertSame($original->getSecretKey(), $imported->getSecretKey());
    }

    public function testInvalidSecretKeyLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Secret key must be exactly 64 bytes');
        Keypair::fromSecretKey(str_repeat("\x00", 32));
    }

    public function testFromJsonArrayRoundTrip(): void
    {
        $original = Keypair::generate();
        $json = json_encode($original->toJsonArray());
        $this->assertIsString($json);

        $imported = Keypair::fromJsonArray($json);
        $this->assertTrue($original->getPublicKey()->equals($imported->getPublicKey()));
    }

    public function testFromJsonArrayAcceptsArray(): void
    {
        $original = Keypair::generate();
        $imported = Keypair::fromJsonArray($original->toJsonArray());
        $this->assertTrue($original->getPublicKey()->equals($imported->getPublicKey()));
    }

    public function testFromJsonArrayInvalidLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 64 integers');
        Keypair::fromJsonArray([1, 2, 3]);
    }

    public function testFromJsonArrayInvalidByteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $arr = array_fill(0, 64, 0);
        $arr[10] = 256;
        Keypair::fromJsonArray($arr);
    }

    public function testFromJsonArrayRejectsNonJsonString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Keypair::fromJsonArray('not json');
    }

    public function testSignAndVerifyRoundTrip(): void
    {
        $kp = Keypair::generate();
        $message = 'Solana PHP SDK test message';
        $signature = $kp->sign($message);

        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($signature));
        $this->assertTrue(Keypair::verify($signature, $message, $kp->getPublicKey()));
    }

    public function testVerifyFailsForTamperedMessage(): void
    {
        $kp = Keypair::generate();
        $signature = $kp->sign('original');
        $this->assertFalse(Keypair::verify($signature, 'tampered', $kp->getPublicKey()));
    }

    public function testVerifyFailsForWrongPublicKey(): void
    {
        $kp1 = Keypair::generate();
        $kp2 = Keypair::generate();
        $signature = $kp1->sign('message');
        $this->assertFalse(Keypair::verify($signature, 'message', $kp2->getPublicKey()));
    }

    public function testVerifyFailsForInvalidSignatureLength(): void
    {
        $kp = Keypair::generate();
        $this->assertFalse(Keypair::verify('short', 'message', $kp->getPublicKey()));
    }

    public function testSignaturesAreDeterministic(): void
    {
        // Ed25519 is deterministic — same message + key always produces same signature.
        $seed = str_repeat("\x07", 32);
        $kp = Keypair::fromSeed($seed);
        $message = 'determinism check';

        $sig1 = $kp->sign($message);
        $sig2 = $kp->sign($message);

        $this->assertSame(bin2hex($sig1), bin2hex($sig2));
    }
}
