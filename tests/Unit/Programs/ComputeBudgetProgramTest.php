<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Programs;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Programs\ComputeBudgetProgram;

final class ComputeBudgetProgramTest extends TestCase
{
    /**
     * All ComputeBudget instructions must:
     *   - target the ComputeBudget111111111111111111111111111111 program
     *   - have EMPTY account lists
     *   - encode correctly per the web3.js golden vectors
     */
    public function testMatchesWeb3JsVectors(): void
    {
        $fixtures = require __DIR__ . '/fixtures_programs.php';
        foreach ($fixtures['computeBudget'] as [$label, $expectedHex, $args]) {
            [$method, $value] = $args;
            $ix = ComputeBudgetProgram::{$method}($value);

            $this->assertSame(
                ComputeBudgetProgram::PROGRAM_ID,
                $ix->programId->toBase58(),
                "{$label}: programId must be ComputeBudget111..."
            );
            $this->assertSame([], $ix->accounts, "{$label}: account list must be empty");
            $this->assertSame(
                $expectedHex,
                bin2hex($ix->data),
                "{$label}: instruction data must match web3.js"
            );
        }
    }

    public function testSetComputeUnitLimitRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComputeBudgetProgram::setComputeUnitLimit(-1);
    }

    public function testSetComputeUnitLimitRejectsAboveU32Max(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComputeBudgetProgram::setComputeUnitLimit(0x100000000);
    }

    public function testSetComputeUnitPriceAcceptsU64Max(): void
    {
        // u64 max as numeric string — should not throw.
        $ix = ComputeBudgetProgram::setComputeUnitPrice('18446744073709551615');
        // Expected: 0x03 + 8 x 0xff
        $this->assertSame('03ffffffffffffffff', bin2hex($ix->data));
    }

    public function testRequestHeapFrameRejectsNonMultipleOf1024(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('multiple of');
        // 33_000 is in the valid range [32768, 262144] but not a multiple of 1024.
        ComputeBudgetProgram::requestHeapFrame(33_000);
    }

    public function testRequestHeapFrameRejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComputeBudgetProgram::requestHeapFrame(1024); // below minimum
    }

    public function testSetLoadedAccountsDataSizeLimitRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComputeBudgetProgram::setLoadedAccountsDataSizeLimit(0);
    }
}
