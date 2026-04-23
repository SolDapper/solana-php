<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\SolanaPhpSdk;

/**
 * Verifies library-wide metadata: version string format, package identifier,
 * User-Agent assembly.
 *
 * These are trivial but valuable: they catch release-time regressions like
 * forgetting to bump the constant, accidentally shipping a placeholder, or
 * removing the class during a refactor.
 */
final class SolanaPhpSdkTest extends TestCase
{
    public function testVersionIsDefined(): void
    {
        $this->assertIsString(SolanaPhpSdk::VERSION);
        $this->assertNotSame('', SolanaPhpSdk::VERSION);
    }

    public function testVersionIsSemverShaped(): void
    {
        // X.Y.Z with optional -suffix. Accepts 0.1.0, 1.0.0, 1.0.0-dev, 1.0.0-rc.1, etc.
        // Rejects sillies like "1.0", "v1.0.0", "" or "TODO".
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/',
            SolanaPhpSdk::VERSION,
            'Version must be semver-shaped (X.Y.Z with optional -suffix)'
        );
    }

    public function testPackageIdentifierIsCorrect(): void
    {
        // Must match composer.json's `name` field so User-Agent strings align
        // with what providers see in their Packagist/registry telemetry.
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertSame($composerJson['name'], SolanaPhpSdk::PACKAGE);
    }

    public function testUserAgentCombinesPackageAndVersion(): void
    {
        $ua = SolanaPhpSdk::userAgent();
        $this->assertStringContainsString(SolanaPhpSdk::PACKAGE, $ua);
        $this->assertStringContainsString(SolanaPhpSdk::VERSION, $ua);
        // Standard User-Agent format: "name/version" or "name version" — we use space
        // but the important thing is providers can parse "which library is this".
        $this->assertStringStartsWith('solana-php/', $ua);
    }

    public function testClassIsNotInstantiable(): void
    {
        $reflection = new \ReflectionClass(SolanaPhpSdk::class);
        $ctor = $reflection->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate(), 'Utility class must have a private constructor');
        $this->assertTrue($reflection->isFinal(), 'Utility class should be final');
    }
}
