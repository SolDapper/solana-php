<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Programs;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Programs\MemoProgram;

final class MemoProgramTest extends TestCase
{
    public function testMemoDataIsRawUtf8Bytes(): void
    {
        $ix = MemoProgram::create('order:12345');
        $this->assertSame(MemoProgram::PROGRAM_ID_V2, $ix->programId->toBase58());
        $this->assertSame('order:12345', $ix->data);
        $this->assertSame([], $ix->accounts);
    }

    public function testEmptyMemoAllowed(): void
    {
        // Rare but not forbidden by the memo program.
        $ix = MemoProgram::create('');
        $this->assertSame('', $ix->data);
    }

    public function testUtf8MemoPreserved(): void
    {
        $memo = '注文番号: 12345';
        $ix = MemoProgram::create($memo);
        $this->assertSame($memo, $ix->data);
        // Byte length of "注文番号: 12345" = 14 chars but UTF-8 encoded = 18 bytes
        // (each of the 4 kanji + ':' are 3 bytes, rest ASCII)
        $this->assertSame(strlen($memo), strlen($ix->data));
    }

    public function testInvalidUtf8Rejected(): void
    {
        // Invalid UTF-8 sequence (lone continuation byte)
        $this->expectException(InvalidArgumentException::class);
        MemoProgram::create("\xc3\x28");
    }

    public function testSignersAddedAsReadonlySignerAccounts(): void
    {
        $signer1 = Keypair::generate()->getPublicKey();
        $signer2 = Keypair::generate()->getPublicKey();
        $ix = MemoProgram::create('with signers', [$signer1, $signer2]);

        $this->assertCount(2, $ix->accounts);
        foreach ($ix->accounts as $meta) {
            $this->assertTrue($meta->isSigner);
            $this->assertFalse($meta->isWritable);
        }
    }

    public function testNonPublicKeySignerRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MemoProgram::create('memo', ['not-a-pubkey']);
    }
}
