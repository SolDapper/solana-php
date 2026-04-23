<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Programs;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\AssociatedTokenProgram;
use SolanaPhpSdk\Programs\SystemProgram;
use SolanaPhpSdk\Programs\TokenProgram;

final class AssociatedTokenProgramTest extends TestCase
{
    private const USDC = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';

    public function testFindAssociatedTokenAddressIsDeterministic(): void
    {
        $owner = Keypair::fromSeed(str_repeat("\x01", 32))->getPublicKey();
        $mint = new PublicKey(self::USDC);

        [$ata1, $bump1] = AssociatedTokenProgram::findAssociatedTokenAddress($owner, $mint);
        [$ata2, $bump2] = AssociatedTokenProgram::findAssociatedTokenAddress($owner, $mint);

        $this->assertTrue($ata1->equals($ata2));
        $this->assertSame($bump1, $bump2);
    }

    public function testFindAssociatedTokenAddressMatchesPublicKeyTest(): void
    {
        // This is the same derivation that the PublicKeyTest golden fixtures
        // verify against @solana/web3.js. Here we just confirm the ATA
        // helper uses the right seeds / program IDs to produce the same result.
        $owner = Keypair::fromSeed(str_repeat("\x01", 32))->getPublicKey();
        $mint = new PublicKey(self::USDC);

        [$ataFromHelper, ] = AssociatedTokenProgram::findAssociatedTokenAddress($owner, $mint);

        // Expected from fixtures_curve.php
        $this->assertSame('3wvJdyFnGvaMWpbq93NU91SggiVRveULUXL6iX5VZDGP', $ataFromHelper->toBase58());
    }

    public function testCreateHasEmptyData(): void
    {
        $payer = Keypair::generate()->getPublicKey();
        $owner = Keypair::generate()->getPublicKey();
        $mint = new PublicKey(self::USDC);
        [$ata, ] = AssociatedTokenProgram::findAssociatedTokenAddress($owner, $mint);

        $ix = AssociatedTokenProgram::create($payer, $ata, $owner, $mint);
        $this->assertSame('', $ix->data, 'Non-idempotent Create has empty instruction data');
    }

    public function testCreateIdempotentHasOneByteData(): void
    {
        $payer = Keypair::generate()->getPublicKey();
        $owner = Keypair::generate()->getPublicKey();
        $mint = new PublicKey(self::USDC);
        [$ata, ] = AssociatedTokenProgram::findAssociatedTokenAddress($owner, $mint);

        $ix = AssociatedTokenProgram::createIdempotent($payer, $ata, $owner, $mint);
        $this->assertSame("\x01", $ix->data);
    }

    public function testAccountLayoutMatchesWeb3Js(): void
    {
        // web3.js layout: [payer (sw), ata (w), owner (r), mint (r), system (r), tokenProgram (r)]
        $payer = Keypair::generate()->getPublicKey();
        $owner = Keypair::generate()->getPublicKey();
        $mint = new PublicKey(self::USDC);
        [$ata, ] = AssociatedTokenProgram::findAssociatedTokenAddress($owner, $mint);

        $ix = AssociatedTokenProgram::createIdempotent($payer, $ata, $owner, $mint);

        $this->assertSame(AssociatedTokenProgram::PROGRAM_ID, $ix->programId->toBase58());
        $this->assertCount(6, $ix->accounts);

        $this->assertTrue($ix->accounts[0]->pubkey->equals($payer));
        $this->assertTrue($ix->accounts[0]->isSigner);
        $this->assertTrue($ix->accounts[0]->isWritable);

        $this->assertTrue($ix->accounts[1]->pubkey->equals($ata));
        $this->assertFalse($ix->accounts[1]->isSigner);
        $this->assertTrue($ix->accounts[1]->isWritable);

        $this->assertTrue($ix->accounts[2]->pubkey->equals($owner));
        $this->assertFalse($ix->accounts[2]->isSigner);
        $this->assertFalse($ix->accounts[2]->isWritable);

        $this->assertTrue($ix->accounts[3]->pubkey->equals($mint));
        $this->assertFalse($ix->accounts[3]->isWritable);

        $this->assertSame(SystemProgram::PROGRAM_ID, $ix->accounts[4]->pubkey->toBase58());
        $this->assertFalse($ix->accounts[4]->isSigner);
        $this->assertFalse($ix->accounts[4]->isWritable);

        $this->assertSame(TokenProgram::PROGRAM_ID, $ix->accounts[5]->pubkey->toBase58());
        $this->assertFalse($ix->accounts[5]->isWritable);
    }

    public function testToken2022OverridePlumbsThrough(): void
    {
        $payer = Keypair::generate()->getPublicKey();
        $owner = Keypair::generate()->getPublicKey();
        $mint = new PublicKey(self::USDC);
        [$ata, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
            $owner, $mint, TokenProgram::token2022ProgramId()
        );
        $ix = AssociatedTokenProgram::createIdempotent(
            $payer, $ata, $owner, $mint, TokenProgram::token2022ProgramId()
        );
        $this->assertSame(TokenProgram::TOKEN_2022_PROGRAM_ID, $ix->accounts[5]->pubkey->toBase58());
    }
}
