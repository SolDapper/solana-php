<?php

declare(strict_types=1);

namespace SolanaPhpSdk;

/**
 * Library-wide metadata.
 *
 * The authoritative version string lives here and is exposed at runtime for
 * debugging, User-Agent headers, and any integration that needs to identify
 * which SDK version is making a call.
 *
 * Release process: the value of VERSION should always match the most recent
 * git tag. When preparing a release:
 *
 *   1. Bump this constant
 *   2. Commit the bump
 *   3. Tag with `git tag -a v<VERSION> -m "release <VERSION>"`
 *   4. Push the commit AND the tag
 *
 * Packagist picks up the tag automatically. Intermediate commits between
 * tags carry the suffix `-dev` to distinguish development HEAD from a
 * released version.
 *
 * Version policy (semver, but 0.x-relaxed):
 *   - While on 0.x: minor bumps (0.1 -> 0.2) may contain breaking changes.
 *     Patch bumps (0.1.0 -> 0.1.1) are strictly non-breaking fixes.
 *   - After 1.0: major bumps for breaking changes, minor for features,
 *     patch for fixes, as per standard semver.
 */
final class SolanaPhpSdk
{
    /**
     * The library version.
     *
     * Format: either `X.Y.Z` for a released tag, or `X.Y.Z-dev` for a
     * development commit between tags.
     */
    public const VERSION = '0.1.0';

    /**
     * The package name as published on Packagist. Used as the prefix in
     * User-Agent headers and any other "who am I" identifier.
     */
    public const PACKAGE = 'solana-php/solana-sdk';

    /**
     * A canonical identifier suitable for a User-Agent header or similar.
     * Example: "solana-php/solana-sdk 0.1.0".
     */
    public static function userAgent(): string
    {
        return self::PACKAGE . ' ' . self::VERSION;
    }

    // Not intended to be instantiated.
    private function __construct() {}
}
