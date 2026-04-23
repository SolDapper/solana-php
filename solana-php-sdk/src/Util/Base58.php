<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Util;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\SolanaException;

/**
 * Base58 encoding/decoding using the Bitcoin alphabet.
 *
 * Solana uses the standard Bitcoin Base58 alphabet for public keys, signatures,
 * and transaction IDs. This implementation auto-detects the best available
 * arbitrary-precision math backend:
 *
 *   1. GMP extension (fastest, preferred)
 *   2. BCMath extension (portable fallback)
 *
 * If neither is available, construction will throw. Install one via your
 * system package manager or PHP build.
 */
final class Base58
{
    public const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private const BACKEND_GMP = 'gmp';
    private const BACKEND_BCMATH = 'bcmath';

    private static ?string $backend = null;

    /**
     * Encode raw binary data to a Base58 string.
     *
     * Leading zero bytes in the input are preserved as leading '1' characters
     * in the output, per the standard Base58Check convention.
     *
     * @param string $bytes Raw binary string to encode.
     * @return string Base58-encoded representation.
     * @throws SolanaException If no math backend is available.
     */
    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        // Count leading zero bytes — each becomes a leading '1' in output.
        $leadingZeros = 0;
        $len = strlen($bytes);
        while ($leadingZeros < $len && $bytes[$leadingZeros] === "\x00") {
            $leadingZeros++;
        }

        $backend = self::getBackend();
        $encoded = $backend === self::BACKEND_GMP
            ? self::encodeGmp($bytes)
            : self::encodeBcmath($bytes);

        return str_repeat('1', $leadingZeros) . $encoded;
    }

    /**
     * Decode a Base58 string back to raw binary data.
     *
     * @param string $encoded Base58-encoded string.
     * @return string Raw binary bytes.
     * @throws InvalidArgumentException If the string contains invalid characters.
     * @throws SolanaException If no math backend is available.
     */
    public static function decode(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        // Validate alphabet
        $len = strlen($encoded);
        for ($i = 0; $i < $len; $i++) {
            if (strpos(self::ALPHABET, $encoded[$i]) === false) {
                throw new InvalidArgumentException(
                    "Invalid Base58 character '{$encoded[$i]}' at position {$i}"
                );
            }
        }

        // Count leading '1's — each becomes a leading zero byte in output.
        $leadingOnes = 0;
        while ($leadingOnes < $len && $encoded[$leadingOnes] === '1') {
            $leadingOnes++;
        }

        $backend = self::getBackend();
        $decoded = $backend === self::BACKEND_GMP
            ? self::decodeGmp($encoded)
            : self::decodeBcmath($encoded);

        return str_repeat("\x00", $leadingOnes) . $decoded;
    }

    /**
     * Detect and cache which arbitrary-precision backend is available.
     *
     * @throws SolanaException If neither GMP nor BCMath is installed.
     */
    private static function getBackend(): string
    {
        if (self::$backend !== null) {
            return self::$backend;
        }

        if (extension_loaded('gmp')) {
            self::$backend = self::BACKEND_GMP;
        } elseif (extension_loaded('bcmath')) {
            self::$backend = self::BACKEND_BCMATH;
        } else {
            throw new SolanaException(
                'Base58 requires either the GMP or BCMath PHP extension. ' .
                'GMP is strongly recommended for performance.'
            );
        }

        return self::$backend;
    }

    /**
     * Force a specific backend (primarily for testing parity between implementations).
     */
    public static function setBackend(?string $backend): void
    {
        if ($backend !== null && $backend !== self::BACKEND_GMP && $backend !== self::BACKEND_BCMATH) {
            throw new InvalidArgumentException("Unknown Base58 backend: {$backend}");
        }
        self::$backend = $backend;
    }

    // ----- GMP implementation --------------------------------------------

    private static function encodeGmp(string $bytes): string
    {
        $num = gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $base = gmp_init(58);
        $result = '';

        while (gmp_cmp($num, 0) > 0) {
            [$num, $rem] = [gmp_div_q($num, $base), gmp_div_r($num, $base)];
            $result = self::ALPHABET[gmp_intval($rem)] . $result;
        }

        return $result;
    }

    private static function decodeGmp(string $encoded): string
    {
        $num = gmp_init(0);
        $base = gmp_init(58);
        $len = strlen($encoded);

        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $encoded[$i]);
            $num = gmp_add(gmp_mul($num, $base), $pos);
        }

        if (gmp_cmp($num, 0) === 0) {
            return '';
        }

        return gmp_export($num, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
    }

    // ----- BCMath implementation -----------------------------------------

    private static function encodeBcmath(string $bytes): string
    {
        // Convert bytes to decimal string
        $num = '0';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $num = bcadd(bcmul($num, '256'), (string) ord($bytes[$i]));
        }

        $result = '';
        while (bccomp($num, '0') > 0) {
            $rem = bcmod($num, '58');
            $num = bcdiv($num, '58', 0);
            $result = self::ALPHABET[(int) $rem] . $result;
        }

        return $result;
    }

    private static function decodeBcmath(string $encoded): string
    {
        $num = '0';
        $len = strlen($encoded);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $encoded[$i]);
            $num = bcadd(bcmul($num, '58'), (string) $pos);
        }

        if (bccomp($num, '0') === 0) {
            return '';
        }

        // Convert decimal string back to bytes
        $bytes = '';
        while (bccomp($num, '0') > 0) {
            $rem = bcmod($num, '256');
            $num = bcdiv($num, '256', 0);
            $bytes = chr((int) $rem) . $bytes;
        }

        return $bytes;
    }
}
