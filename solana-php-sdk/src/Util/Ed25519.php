<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Util;

use SolanaPhpSdk\Exception\SolanaException;

/**
 * Ed25519 curve utilities for PHP.
 *
 * PHP's libsodium bindings do not expose sodium_crypto_core_ed25519_is_valid_point
 * (only the ristretto255 variant is available through PHP 8.4). Since Solana
 * Program Derived Address derivation depends on determining whether a 32-byte
 * value decompresses to a valid Ed25519 point, we implement the check directly
 * via GMP-based field arithmetic.
 *
 * This implementation matches the reference behavior of:
 *   - RFC 8032 Section 5.1.3 (point decoding)
 *   - TweetNaCl's unpackneg function
 *   - @solana/web3.js PublicKey.isOnCurve
 *
 * All three agree byte-for-byte on the set of 32-byte values that qualify as
 * "on curve". This implementation is validated against golden vectors from
 * @solana/web3.js in the test suite.
 *
 * The curve equation is the twisted Edwards form:
 *     -x^2 + y^2 = 1 + d * x^2 * y^2  (mod p)
 * where:
 *     p = 2^255 - 19
 *     d = -121665 / 121666 (mod p)
 *
 * Compressed point format (32 bytes, little-endian):
 *     bits 0..254 = y coordinate
 *     bit 255     = sign bit of x
 */
final class Ed25519
{
    /**
     * Check whether a 32-byte value decompresses to a valid point on the
     * Ed25519 curve.
     *
     * Returns false for invalid lengths or any 32-byte value that does not
     * correspond to a valid curve point. Does NOT check for small-subgroup
     * points or other cryptographic edge cases beyond curve membership —
     * this matches the behavior expected by Solana PDA derivation.
     *
     * @throws SolanaException If the GMP extension is not available.
     */
    public static function isOnCurve(string $bytes): bool
    {
        if (strlen($bytes) !== 32) {
            return false;
        }

        if (!extension_loaded('gmp')) {
            throw new SolanaException(
                'Ed25519 curve validation requires the GMP extension. ' .
                'Install php-gmp or use paragonie/sodium_compat as a fallback.'
            );
        }

        // p = 2^255 - 19
        $p = gmp_sub(gmp_pow(2, 255), 19);

        // d = -121665 * modinverse(121666, p)  (precomputed constant)
        $d = gmp_init('37095705934669439343138083508754565189542113879843219016388785533085940283555');

        // Split out the sign bit and clear it to get the y coordinate.
        $signBit = (ord($bytes[31]) >> 7) & 1;
        $yBytes = $bytes;
        $yBytes[31] = chr(ord($yBytes[31]) & 0x7f);

        // Decode y as a little-endian 255-bit integer.
        $y = gmp_init(0);
        for ($i = 31; $i >= 0; $i--) {
            $y = gmp_add(gmp_mul($y, 256), ord($yBytes[$i]));
        }

        // Non-canonical encodings where y >= p are invalid.
        if (gmp_cmp($y, $p) >= 0) {
            return false;
        }

        // Recover x^2 from the curve equation:
        //   x^2 = (y^2 - 1) / (d*y^2 + 1)  (mod p)
        $y2 = gmp_mod(gmp_mul($y, $y), $p);
        $num = gmp_mod(gmp_sub($y2, 1), $p);
        $den = gmp_mod(gmp_add(gmp_mul($d, $y2), 1), $p);

        // Modular inverse via Fermat's little theorem: a^(p-2) ≡ a^(-1) (mod p).
        $denInv = gmp_powm($den, gmp_sub($p, 2), $p);
        $x2 = gmp_mod(gmp_mul($num, $denInv), $p);

        // Compute a candidate square root of x^2.
        // Since p ≡ 5 (mod 8), the Tonelli-Shanks shortcut gives:
        //   x = x2^((p+3)/8) (mod p)
        // If x^2 != x2, multiply by sqrt(-1) = 2^((p-1)/4) and retry.
        $exp = gmp_div_q(gmp_add($p, 3), 8);
        $x = gmp_powm($x2, $exp, $p);

        $xSq = gmp_mod(gmp_mul($x, $x), $p);
        if (gmp_cmp($xSq, $x2) !== 0) {
            // Try x * sqrt(-1)
            $sqrtMinusOne = gmp_powm(gmp_init(2), gmp_div_q(gmp_sub($p, 1), 4), $p);
            $x = gmp_mod(gmp_mul($x, $sqrtMinusOne), $p);
            $xSq = gmp_mod(gmp_mul($x, $x), $p);
            if (gmp_cmp($xSq, $x2) !== 0) {
                // No valid square root — not on curve.
                return false;
            }
        }

        // If x is zero, the sign bit must be zero (canonical encoding rule).
        if (gmp_cmp($x, 0) === 0 && $signBit === 1) {
            return false;
        }

        return true;
    }
}
