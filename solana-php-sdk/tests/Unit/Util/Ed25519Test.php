<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Util\Ed25519;

/**
 * Ed25519 curve-validation tests.
 *
 * The PublicKeyTest suite exercises Ed25519::isOnCurve indirectly through
 * its golden-vector test. These direct tests cover edge cases and document
 * specific behaviors that would be hard to read from the integration tests.
 */
final class Ed25519Test extends TestCase
{
    public function testWrongLengthReturnsFalse(): void
    {
        $this->assertFalse(Ed25519::isOnCurve(''));
        $this->assertFalse(Ed25519::isOnCurve(str_repeat("\x00", 31)));
        $this->assertFalse(Ed25519::isOnCurve(str_repeat("\x00", 33)));
    }

    public function testAllZerosIsOnCurve(): void
    {
        // This matches @solana/web3.js and tweetnacl behavior. All-zeros
        // decompresses to a valid (if degenerate) point.
        $this->assertTrue(Ed25519::isOnCurve(str_repeat("\x00", 32)));
    }

    public function testGeneratedPublicKeysAreAlwaysOnCurve(): void
    {
        // Exhaustively check many randomly generated keypairs — every Ed25519
        // public key, by construction, lies on the curve.
        for ($i = 0; $i < 25; $i++) {
            $kp = sodium_crypto_sign_keypair();
            $pk = sodium_crypto_sign_publickey($kp);
            $this->assertTrue(
                Ed25519::isOnCurve($pk),
                'Generated public key at iteration ' . $i . ' failed curve check: ' . bin2hex($pk)
            );
        }
    }

    public function testCurveCheckApproximatelyHalfOfRandomHashesOnCurve(): void
    {
        // A random 32-byte value lies on the Ed25519 curve with ~50% probability.
        // Across many samples, we expect something close to that.
        $onCurve = 0;
        $total = 200;
        for ($i = 0; $i < $total; $i++) {
            if (Ed25519::isOnCurve(random_bytes(32))) {
                $onCurve++;
            }
        }
        // With n=200, expected ~100, std dev ~7. Allow wide bounds to avoid flakiness.
        $this->assertGreaterThan(50, $onCurve, 'Suspiciously few on-curve hits');
        $this->assertLessThan(150, $onCurve, 'Suspiciously many on-curve hits');
    }

    /**
     * Direct check against the same golden vectors used by PublicKeyTest,
     * but invoking Ed25519::isOnCurve without going through PublicKey.
     */
    public function testGoldenVectorsDirect(): void
    {
        $fixtures = require __DIR__ . '/../Keypair/fixtures_curve.php';
        foreach ($fixtures['curveVectors'] as [$hex, $expected]) {
            $this->assertSame(
                $expected,
                Ed25519::isOnCurve(hex2bin($hex)),
                "Direct Ed25519 mismatch for {$hex}"
            );
        }
    }
}
