<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Programs;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\SystemProgram;

final class SystemProgramTest extends TestCase
{
    private const WSOL = 'So11111111111111111111111111111111111111112';
    private const TOKEN_PROG = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

    private function fromKeypair(): Keypair
    {
        return Keypair::fromSeed(str_repeat("\x07", 32));
    }

    public function testTransferMatchesWeb3Js(): void
    {
        $fixtures = require __DIR__ . '/fixtures_programs.php';
        $vec = $fixtures['system'][0]; // transfer(1.5 SOL)
        [$_, $expectedHex, $args] = $vec;

        $from = $this->fromKeypair()->getPublicKey();
        $to = new PublicKey(self::WSOL);
        $ix = SystemProgram::transfer($from, $to, $args[1]);

        $this->assertSame(SystemProgram::PROGRAM_ID, $ix->programId->toBase58());
        $this->assertSame($expectedHex, bin2hex($ix->data));

        // Account layout: [from (sw), to (w)]
        $this->assertCount(2, $ix->accounts);
        $this->assertTrue($ix->accounts[0]->pubkey->equals($from));
        $this->assertTrue($ix->accounts[0]->isSigner);
        $this->assertTrue($ix->accounts[0]->isWritable);
        $this->assertTrue($ix->accounts[1]->pubkey->equals($to));
        $this->assertFalse($ix->accounts[1]->isSigner);
        $this->assertTrue($ix->accounts[1]->isWritable);
    }

    public function testAllocateMatchesWeb3Js(): void
    {
        $fixtures = require __DIR__ . '/fixtures_programs.php';
        $vec = $fixtures['system'][1]; // allocate(500)
        [$_, $expectedHex, $args] = $vec;

        $account = $this->fromKeypair()->getPublicKey();
        $ix = SystemProgram::allocate($account, $args[1]);

        $this->assertSame($expectedHex, bin2hex($ix->data));
        $this->assertCount(1, $ix->accounts);
        $this->assertTrue($ix->accounts[0]->isSigner);
        $this->assertTrue($ix->accounts[0]->isWritable);
    }

    public function testAssignMatchesWeb3Js(): void
    {
        $fixtures = require __DIR__ . '/fixtures_programs.php';
        $vec = $fixtures['system'][2];
        [$_, $expectedHex, $args] = $vec;

        $account = $this->fromKeypair()->getPublicKey();
        $newOwner = new PublicKey($args[1]);
        $ix = SystemProgram::assign($account, $newOwner);

        $this->assertSame($expectedHex, bin2hex($ix->data));
    }

    public function testCreateAccountMatchesWeb3Js(): void
    {
        $fixtures = require __DIR__ . '/fixtures_programs.php';
        $vec = $fixtures['system'][3];
        [$_, $expectedHex, $args] = $vec;
        [$lamports, $space, $programId] = $args[1];

        $from = $this->fromKeypair()->getPublicKey();
        $newAccount = new PublicKey(self::WSOL);
        $ix = SystemProgram::createAccount(
            $from, $newAccount, $lamports, $space, new PublicKey($programId)
        );

        $this->assertSame($expectedHex, bin2hex($ix->data));

        // Both accounts are signer+writable
        $this->assertCount(2, $ix->accounts);
        $this->assertTrue($ix->accounts[0]->isSigner && $ix->accounts[0]->isWritable);
        $this->assertTrue($ix->accounts[1]->isSigner && $ix->accounts[1]->isWritable);
    }

    public function testTransferAcceptsU64StringAmount(): void
    {
        $from = $this->fromKeypair()->getPublicKey();
        $to = new PublicKey(self::WSOL);
        // Max u64 as string; should not throw
        $ix = SystemProgram::transfer($from, $to, '18446744073709551615');
        // Data: 0x02000000 + 8 x 0xff
        $this->assertSame('02000000ffffffffffffffff', bin2hex($ix->data));
    }
}
