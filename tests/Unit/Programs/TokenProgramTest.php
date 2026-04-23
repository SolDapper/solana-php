<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Programs;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\TokenProgram;

final class TokenProgramTest extends TestCase
{
    private const USDC = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';

    public function testTransferMatchesWeb3Js(): void
    {
        // fixtures entry 0: transfer(1 USDC) => '0340420f0000000000'
        $fixtures = require __DIR__ . '/fixtures_programs.php';
        [$_, $expectedHex, $args] = $fixtures['token'][0];

        $owner = Keypair::fromSeed(str_repeat("\x09", 32))->getPublicKey();
        $src = new PublicKey('7RP22uEk2BNSbZaQDPAXEqFzcqgoo7qiDiM3VwpgwXc1');
        $dst = new PublicKey('5vUprZVMWkZgRdVKoEFp5fJHdeWvGXJQrGErFNwAysL4');

        $ix = TokenProgram::transfer($src, $dst, $owner, $args[1]);

        $this->assertSame(TokenProgram::PROGRAM_ID, $ix->programId->toBase58());
        $this->assertSame($expectedHex, bin2hex($ix->data));

        // Account order: [src (w), dst (w), owner (signer, r)]
        $this->assertCount(3, $ix->accounts);
        $this->assertTrue($ix->accounts[0]->pubkey->equals($src));
        $this->assertFalse($ix->accounts[0]->isSigner);
        $this->assertTrue($ix->accounts[0]->isWritable);

        $this->assertTrue($ix->accounts[1]->pubkey->equals($dst));
        $this->assertTrue($ix->accounts[1]->isWritable);

        $this->assertTrue($ix->accounts[2]->pubkey->equals($owner));
        $this->assertTrue($ix->accounts[2]->isSigner);
        $this->assertFalse($ix->accounts[2]->isWritable);
    }

    public function testTransferCheckedMatchesWeb3Js(): void
    {
        $fixtures = require __DIR__ . '/fixtures_programs.php';
        [$_, $expectedHex, $args] = $fixtures['token'][1];
        [$amount, $decimals] = $args[1];

        $owner = Keypair::fromSeed(str_repeat("\x09", 32))->getPublicKey();
        $src = new PublicKey('7RP22uEk2BNSbZaQDPAXEqFzcqgoo7qiDiM3VwpgwXc1');
        $dst = new PublicKey('5vUprZVMWkZgRdVKoEFp5fJHdeWvGXJQrGErFNwAysL4');
        $mint = new PublicKey(self::USDC);

        $ix = TokenProgram::transferChecked($src, $mint, $dst, $owner, $amount, $decimals);

        $this->assertSame($expectedHex, bin2hex($ix->data));

        // Account order: [src (w), mint (r), dst (w), owner (signer, r)]
        $this->assertCount(4, $ix->accounts);
        $this->assertTrue($ix->accounts[0]->pubkey->equals($src));
        $this->assertTrue($ix->accounts[0]->isWritable);

        $this->assertTrue($ix->accounts[1]->pubkey->equals($mint));
        $this->assertFalse($ix->accounts[1]->isSigner);
        $this->assertFalse($ix->accounts[1]->isWritable);

        $this->assertTrue($ix->accounts[2]->pubkey->equals($dst));
        $this->assertTrue($ix->accounts[2]->isWritable);

        $this->assertTrue($ix->accounts[3]->pubkey->equals($owner));
        $this->assertTrue($ix->accounts[3]->isSigner);
        $this->assertFalse($ix->accounts[3]->isWritable);
    }

    public function testTransferCheckedRejectsInvalidDecimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $owner = Keypair::generate()->getPublicKey();
        $src = Keypair::generate()->getPublicKey();
        $dst = Keypair::generate()->getPublicKey();
        $mint = new PublicKey(self::USDC);
        TokenProgram::transferChecked($src, $mint, $dst, $owner, 1, 256);
    }

    public function testTransferAcceptsCustomTokenProgramId(): void
    {
        $owner = Keypair::generate()->getPublicKey();
        $src = Keypair::generate()->getPublicKey();
        $dst = Keypair::generate()->getPublicKey();
        $t22 = TokenProgram::token2022ProgramId();
        $ix = TokenProgram::transfer($src, $dst, $owner, 1, $t22);
        $this->assertSame(TokenProgram::TOKEN_2022_PROGRAM_ID, $ix->programId->toBase58());
    }
}
