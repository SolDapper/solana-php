<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Transaction;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\SolanaException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Transaction\AccountMeta;
use SolanaPhpSdk\Transaction\Message;
use SolanaPhpSdk\Transaction\Transaction;
use SolanaPhpSdk\Transaction\TransactionInstruction;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Legacy Transaction / Message serialization tests.
 *
 * The headline test here — testMatchesWeb3JsReferenceVectors — locks in
 * byte-for-byte parity with the JavaScript @solana/web3.js reference
 * implementation across three representative transaction shapes. These are
 * the most load-bearing assertions in the whole SDK: if they fail, no
 * transaction this SDK produces will be accepted on-chain.
 */
final class TransactionTest extends TestCase
{
    private const SYSTEM_PROGRAM_ID = '11111111111111111111111111111111';
    private const MEMO_PROGRAM_ID   = 'MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr';

    /**
     * Build a System Program transfer instruction, matching SystemProgram.transfer
     * from @solana/web3.js. The instruction data is [u32 discriminator=2] + [u64 lamports].
     */
    private static function systemTransfer(PublicKey $from, PublicKey $to, int $lamports): TransactionInstruction
    {
        $data = (new ByteBuffer())
            ->writeU32(2) // discriminator for Transfer
            ->writeU64($lamports)
            ->toBytes();

        return new TransactionInstruction(
            new PublicKey(self::SYSTEM_PROGRAM_ID),
            [
                AccountMeta::signerWritable($from),
                AccountMeta::writable($to),
            ],
            $data
        );
    }

    public function testMatchesWeb3JsReferenceVectors(): void
    {
        $fixtures = require __DIR__ . '/fixtures_transactions.php';

        // --- single transfer ---
        $v = $fixtures['single_transfer'];
        $payer = Keypair::fromSeed(str_repeat(chr($v['feePayerSeedByte']), 32));
        $recipient = new PublicKey($v['recipient']);
        $blockhash = str_repeat("\x00", 32);

        $tx = Transaction::new(
            [self::systemTransfer($payer->getPublicKey(), $recipient, $v['lamports'])],
            $payer->getPublicKey(),
            $blockhash
        );

        $this->assertSame(
            $v['msg_hex'],
            bin2hex($tx->message->serialize()),
            'single_transfer: message bytes must match web3.js'
        );

        $tx->sign($payer);
        $this->assertSame(
            $v['sig_hex'],
            bin2hex($tx->signatures[0]),
            'single_transfer: Ed25519 signature must match web3.js'
        );
        $this->assertSame(
            $v['wire_hex'],
            bin2hex($tx->serialize()),
            'single_transfer: full serialized transaction must match web3.js'
        );

        // --- two transfers ---
        $v = $fixtures['two_transfers'];
        $payer = Keypair::fromSeed(str_repeat(chr($v['feePayerSeedByte']), 32));
        $recipient = new PublicKey($v['recipient']);

        $ixs = [];
        foreach ($v['lamports_list'] as $amount) {
            $ixs[] = self::systemTransfer($payer->getPublicKey(), $recipient, $amount);
        }
        $tx = Transaction::new($ixs, $payer->getPublicKey(), $blockhash);

        $this->assertSame($v['msg_hex'], bin2hex($tx->message->serialize()), 'two_transfers: message');
        $tx->sign($payer);
        $this->assertSame($v['wire_hex'], bin2hex($tx->serialize()), 'two_transfers: wire');

        // --- memo-only ---
        $v = $fixtures['memo_only'];
        $payer = Keypair::fromSeed(str_repeat(chr($v['feePayerSeedByte']), 32));

        $memoIx = new TransactionInstruction(
            new PublicKey(self::MEMO_PROGRAM_ID),
            [], // no accounts (other than implied fee payer)
            $v['memo']
        );
        $tx = Transaction::new([$memoIx], $payer->getPublicKey(), $blockhash);

        $this->assertSame($v['msg_hex'], bin2hex($tx->message->serialize()), 'memo_only: message');
        $tx->sign($payer);
        $this->assertSame($v['wire_hex'], bin2hex($tx->serialize()), 'memo_only: wire');
    }

    // ----- Message compilation behaviors ----------------------------------

    public function testFeePayerIsAlwaysFirst(): void
    {
        $payer = Keypair::generate();
        $other = Keypair::generate();
        $bh = str_repeat("\x00", 32);

        // An instruction where fee payer isn't even mentioned.
        $ix = new TransactionInstruction(
            new PublicKey(self::SYSTEM_PROGRAM_ID),
            [AccountMeta::signerWritable($other->getPublicKey())],
            ''
        );

        $msg = Message::compile([$ix], $payer->getPublicKey(), $bh);
        $this->assertTrue($msg->accountKeys[0]->equals($payer->getPublicKey()));
        $this->assertTrue($msg->isAccountSigner(0));
        $this->assertTrue($msg->isAccountWritable(0));
    }

    public function testAccountsDeduplicatedAcrossInstructions(): void
    {
        $payer = Keypair::generate();
        $recipient = new PublicKey('So11111111111111111111111111111111111111112');
        $bh = str_repeat("\x00", 32);

        $ix1 = self::systemTransfer($payer->getPublicKey(), $recipient, 100);
        $ix2 = self::systemTransfer($payer->getPublicKey(), $recipient, 200);

        $msg = Message::compile([$ix1, $ix2], $payer->getPublicKey(), $bh);

        // Expect exactly 3 unique accounts: payer, recipient, system program.
        $this->assertCount(3, $msg->accountKeys);
        $this->assertCount(2, $msg->instructions);
    }

    public function testSignerFlagsMergedAcrossInstructions(): void
    {
        // If account X is signer+writable in one instruction and readonly in
        // another, it should end up in the writable-signer category.
        $payer = Keypair::generate();
        $shared = Keypair::generate();
        $bh = str_repeat("\x00", 32);

        $ix1 = new TransactionInstruction(
            new PublicKey(self::SYSTEM_PROGRAM_ID),
            [AccountMeta::signerWritable($shared->getPublicKey())],
            ''
        );
        $ix2 = new TransactionInstruction(
            new PublicKey(self::MEMO_PROGRAM_ID),
            [AccountMeta::readonly($shared->getPublicKey())],
            ''
        );

        $msg = Message::compile([$ix1, $ix2], $payer->getPublicKey(), $bh);

        // Find the shared account's index and verify its flags.
        $idx = null;
        foreach ($msg->accountKeys as $i => $pk) {
            if ($pk->equals($shared->getPublicKey())) {
                $idx = $i;
                break;
            }
        }
        $this->assertNotNull($idx);
        $this->assertTrue($msg->isAccountSigner($idx));
        $this->assertTrue($msg->isAccountWritable($idx));
    }

    public function testAccountCategoryOrdering(): void
    {
        // Build a message with all four categories present and verify ordering.
        $payer = Keypair::generate();                          // writable signer (index 0)
        $readonlySigner = Keypair::generate();                 // readonly signer
        $writableAccount = new PublicKey('So11111111111111111111111111111111111111112');
        $readonlyAccount = new PublicKey(self::MEMO_PROGRAM_ID); // will appear as program
        $bh = str_repeat("\x00", 32);

        $ix = new TransactionInstruction(
            $readonlyAccount,
            [
                AccountMeta::signerWritable($payer->getPublicKey()),
                AccountMeta::signerReadonly($readonlySigner->getPublicKey()),
                AccountMeta::writable($writableAccount),
            ],
            ''
        );

        $msg = Message::compile([$ix], $payer->getPublicKey(), $bh);

        // Expected ordering in accountKeys:
        //   0: payer (writable signer)
        //   1: readonlySigner (readonly signer)
        //   2: writableAccount (writable non-signer)
        //   3: readonlyAccount / memo program (readonly non-signer)
        $this->assertCount(4, $msg->accountKeys);
        $this->assertTrue($msg->accountKeys[0]->equals($payer->getPublicKey()));
        $this->assertTrue($msg->accountKeys[1]->equals($readonlySigner->getPublicKey()));
        $this->assertTrue($msg->accountKeys[2]->equals($writableAccount));
        $this->assertTrue($msg->accountKeys[3]->equals($readonlyAccount));

        // Header counts
        $this->assertSame(2, $msg->numRequiredSignatures);        // payer + readonlySigner
        $this->assertSame(1, $msg->numReadonlySignedAccounts);    // readonlySigner
        $this->assertSame(1, $msg->numReadonlyUnsignedAccounts);  // memo program

        // Index-based flag checks
        $this->assertTrue($msg->isAccountSigner(0));
        $this->assertTrue($msg->isAccountWritable(0));
        $this->assertTrue($msg->isAccountSigner(1));
        $this->assertFalse($msg->isAccountWritable(1));
        $this->assertFalse($msg->isAccountSigner(2));
        $this->assertTrue($msg->isAccountWritable(2));
        $this->assertFalse($msg->isAccountSigner(3));
        $this->assertFalse($msg->isAccountWritable(3));
    }

    public function testMessageRoundTripSerialization(): void
    {
        $payer = Keypair::fromSeed(str_repeat("\x01", 32));
        $recipient = new PublicKey('So11111111111111111111111111111111111111112');
        $bh = str_repeat("\x00", 32);

        $msg = Message::compile(
            [self::systemTransfer($payer->getPublicKey(), $recipient, 12345)],
            $payer->getPublicKey(),
            $bh
        );

        $bytes = $msg->serialize();
        $decoded = Message::deserialize($bytes);
        $this->assertSame(bin2hex($bytes), bin2hex($decoded->serialize()));
    }

    public function testTransactionRoundTripWithSignature(): void
    {
        $payer = Keypair::fromSeed(str_repeat("\x02", 32));
        $recipient = new PublicKey('So11111111111111111111111111111111111111112');
        $bh = str_repeat("\x00", 32);

        $tx = Transaction::new(
            [self::systemTransfer($payer->getPublicKey(), $recipient, 42)],
            $payer->getPublicKey(),
            $bh
        );
        $tx->sign($payer);

        $wire = $tx->serialize();
        $restored = Transaction::deserialize($wire);

        $this->assertSame(bin2hex($wire), bin2hex($restored->serialize()));
        $this->assertTrue($restored->verifySignatures());
    }

    public function testVerifySignaturesDetectsTampering(): void
    {
        $payer = Keypair::generate();
        $recipient = new PublicKey('So11111111111111111111111111111111111111112');
        $bh = str_repeat("\x00", 32);

        $tx = Transaction::new(
            [self::systemTransfer($payer->getPublicKey(), $recipient, 100)],
            $payer->getPublicKey(),
            $bh
        );
        $tx->sign($payer);
        $this->assertTrue($tx->verifySignatures());

        // Tamper with the message bytes (change the blockhash).
        $tx->message->recentBlockhash = str_repeat("\xff", 32);
        $this->assertFalse($tx->verifySignatures());
    }

    public function testSignRejectsNonSignerKeypair(): void
    {
        $payer = Keypair::generate();
        $stranger = Keypair::generate();
        $recipient = new PublicKey('So11111111111111111111111111111111111111112');
        $bh = str_repeat("\x00", 32);

        $tx = Transaction::new(
            [self::systemTransfer($payer->getPublicKey(), $recipient, 100)],
            $payer->getPublicKey(),
            $bh
        );

        $this->expectException(SolanaException::class);
        $this->expectExceptionMessage('is not a required signer');
        $tx->sign($stranger);
    }

    public function testSerializeRejectsUnsignedTransaction(): void
    {
        $payer = Keypair::generate();
        $recipient = new PublicKey('So11111111111111111111111111111111111111112');
        $bh = str_repeat("\x00", 32);

        $tx = Transaction::new(
            [self::systemTransfer($payer->getPublicKey(), $recipient, 100)],
            $payer->getPublicKey(),
            $bh
        );

        $this->expectException(SolanaException::class);
        $this->expectExceptionMessage('missing signature');
        $tx->serialize(); // verifySignatures = true by default
    }

    public function testSerializeAllowsUnsignedWhenRequested(): void
    {
        $payer = Keypair::generate();
        $recipient = new PublicKey('So11111111111111111111111111111111111111112');
        $bh = str_repeat("\x00", 32);

        $tx = Transaction::new(
            [self::systemTransfer($payer->getPublicKey(), $recipient, 100)],
            $payer->getPublicKey(),
            $bh
        );

        $wire = $tx->serialize(false);
        $this->assertIsString($wire);
        // First signature slot should be all zeros (the null signature).
        $this->assertSame(str_repeat("\x00", 64), substr($wire, 1, 64));
    }

    public function testPartialSignLeavesOtherSlotsNull(): void
    {
        $payer = Keypair::generate();
        $coSigner = Keypair::generate();
        $bh = str_repeat("\x00", 32);

        // Two signers required: payer (writable) + coSigner (readonly)
        $ix = new TransactionInstruction(
            new PublicKey(self::MEMO_PROGRAM_ID),
            [
                AccountMeta::signerWritable($payer->getPublicKey()),
                AccountMeta::signerReadonly($coSigner->getPublicKey()),
            ],
            ''
        );
        $tx = Transaction::new([$ix], $payer->getPublicKey(), $bh);

        $tx->partialSign($payer);

        $this->assertNotSame(str_repeat("\x00", 64), $tx->signatures[0]);
        $this->assertSame(str_repeat("\x00", 64), $tx->signatures[1]);
        $this->assertFalse($tx->verifySignatures()); // Still missing coSigner

        $tx->partialSign($coSigner);
        $this->assertTrue($tx->verifySignatures());
    }

    public function testEmptyInstructionsThrows(): void
    {
        $this->expectException(\SolanaPhpSdk\Exception\InvalidArgumentException::class);
        Message::compile([], Keypair::generate()->getPublicKey(), str_repeat("\x00", 32));
    }
}
