<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Transaction;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\SystemProgram;
use SolanaPhpSdk\Transaction\AccountMeta;
use SolanaPhpSdk\Transaction\AddressLookupTableAccount;
use SolanaPhpSdk\Transaction\MessageV0;
use SolanaPhpSdk\Transaction\TransactionInstruction;
use SolanaPhpSdk\Transaction\VersionedTransaction;

/**
 * MessageV0 and VersionedTransaction byte-for-byte parity tests vs. @solana/web3.js.
 *
 * Three golden cases from the reference implementation:
 *   case1 — No ALTs, simple transfer. Validates the version prefix handling
 *           and confirms v0 output matches web3.js exactly for a "legacy-shaped"
 *           payload.
 *   case2 — Single ALT with two readonly lookups. Validates the drain algorithm:
 *           accounts that could be drained (non-signer, non-invoked, present
 *           in ALT) get removed from static keys and referenced by index.
 *   case3 — Mixed writable + readonly lookups from one ALT. Validates the
 *           combined account-list indexing: static keys first, then writable
 *           resolved, then readonly resolved.
 */
final class VersionedTransactionTest extends TestCase
{
    private const BLOCKHASH = 'GHtXQBsoZHVnNFa9YevAzFr17DJjgHXk3ycTKD5xD3Zi';

    private Keypair $payer;
    private Keypair $a;
    private Keypair $b;
    private Keypair $c;

    protected function setUp(): void
    {
        $this->payer = Keypair::fromSeed(str_repeat("\x01", 32));
        $this->a     = Keypair::fromSeed(str_repeat("\x02", 32));
        $this->b     = Keypair::fromSeed(str_repeat("\x03", 32));
        $this->c     = Keypair::fromSeed(str_repeat("\x04", 32));
    }

    public function testSeedDerivationMatchesReference(): void
    {
        // Sanity: if this fails, the pubkeys diverge from the JS reference
        // before we even test messages — helps distinguish keypair bugs from
        // compile-algorithm bugs.
        $this->assertSame('AKnL4NNf3DGWZJS6cPknBuEGnVsV4A4m5tgebLHaRSZ9', $this->payer->getPublicKey()->toBase58());
        $this->assertSame('9hSR6S7WPtxmTojgo6GG3k4yDPecgJY292j7xrsUGWBu', $this->a->getPublicKey()->toBase58());
        $this->assertSame('GyGKxMyg1p9SsHfm15MkNUu1u9TN2JtTspcdmrtGUdse', $this->b->getPublicKey()->toBase58());
        $this->assertSame('EdmxWPmx2WH6WgFfTdu9xfkYf3k1g5wD1zccTVySEEh1', $this->c->getPublicKey()->toBase58());
    }

    // =============== Case 1: v0 no-ALT transfer ==================

    public function testCase1NoAltTransferMessageSerializesExactly(): void
    {
        $fx = require __DIR__ . '/fixtures_v0.php';
        $vec = $fx['case1_no_alt_transfer'];

        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1000)],
            self::BLOCKHASH,
            []
        );

        $this->assertSame($vec['msg_hex'], bin2hex($msg->serialize()), 'Case 1 message bytes');
        $this->assertSame($vec['header'][0], $msg->numRequiredSignatures);
        $this->assertSame($vec['header'][1], $msg->numReadonlySignedAccounts);
        $this->assertSame($vec['header'][2], $msg->numReadonlyUnsignedAccounts);

        $this->assertCount(count($vec['static_keys']), $msg->staticAccountKeys);
        foreach ($vec['static_keys'] as $i => $expected) {
            $this->assertSame($expected, $msg->staticAccountKeys[$i]->toBase58(), "static[{$i}]");
        }
        $this->assertSame($vec['num_lookups'], count($msg->addressTableLookups));
    }

    public function testCase1NoAltTransactionSignsExactly(): void
    {
        $fx = require __DIR__ . '/fixtures_v0.php';
        $vec = $fx['case1_no_alt_transfer'];

        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1000)],
            self::BLOCKHASH,
            []
        );
        $tx = new VersionedTransaction($msg);
        $tx->sign($this->payer);

        $this->assertSame($vec['tx_hex'], bin2hex($tx->serialize()), 'Case 1 signed tx bytes');
        $this->assertTrue($tx->verifySignatures());
    }

    // =============== Case 2: ALT with 2 readonly lookups ==================

    public function testCase2AltTwoReadonlyLookups(): void
    {
        $fx = require __DIR__ . '/fixtures_v0.php';
        $vec = $fx['case2_alt_two_readonly'];

        $altKey = Keypair::fromSeed(str_repeat("\x10", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            $this->b->getPublicKey(),   // idx 0
            $this->c->getPublicKey(),   // idx 1
        ]);

        // Instruction: payer (sw), a (w), b (r), c (r)
        $ix = new TransactionInstruction(
            SystemProgram::programId(),
            [
                AccountMeta::signerWritable($this->payer->getPublicKey()),
                AccountMeta::writable($this->a->getPublicKey()),
                AccountMeta::readonly($this->b->getPublicKey()),
                AccountMeta::readonly($this->c->getPublicKey()),
            ],
            "\x00\x01\x02\x03"
        );

        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [$ix],
            self::BLOCKHASH,
            [$alt]
        );

        // Verify structural expectations before checking byte parity — gives
        // more informative failure messages when something diverges.
        $this->assertSame($vec['header'][0], $msg->numRequiredSignatures);
        $this->assertCount(count($vec['static_keys']), $msg->staticAccountKeys);
        foreach ($vec['static_keys'] as $i => $expected) {
            $this->assertSame($expected, $msg->staticAccountKeys[$i]->toBase58(), "static[{$i}]");
        }
        $this->assertCount(1, $msg->addressTableLookups);
        $this->assertSame($vec['lookup_key'], $msg->addressTableLookups[0]->accountKey->toBase58());
        $this->assertSame($vec['writable_idxs'], $msg->addressTableLookups[0]->writableIndexes);
        $this->assertSame($vec['readonly_idxs'], $msg->addressTableLookups[0]->readonlyIndexes);

        $this->assertSame($vec['msg_hex'], bin2hex($msg->serialize()), 'Case 2 message bytes');
    }

    // =============== Case 3: mixed writable + readonly lookups ==================

    public function testCase3AltMixedWritableAndReadonly(): void
    {
        $fx = require __DIR__ . '/fixtures_v0.php';
        $vec = $fx['case3_alt_mixed'];

        $altKey = Keypair::fromSeed(str_repeat("\x11", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            $this->a->getPublicKey(),   // idx 0 (will be used writable)
            $this->b->getPublicKey(),   // idx 1 (unused)
            $this->c->getPublicKey(),   // idx 2 (will be used readonly)
        ]);

        // Instruction: payer (sw), a (w via ALT), c (r via ALT)
        $ix = new TransactionInstruction(
            SystemProgram::programId(),
            [
                AccountMeta::signerWritable($this->payer->getPublicKey()),
                AccountMeta::writable($this->a->getPublicKey()),
                AccountMeta::readonly($this->c->getPublicKey()),
            ],
            "\x42"
        );

        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [$ix],
            self::BLOCKHASH,
            [$alt]
        );

        $this->assertCount(count($vec['static_keys']), $msg->staticAccountKeys);
        foreach ($vec['static_keys'] as $i => $expected) {
            $this->assertSame($expected, $msg->staticAccountKeys[$i]->toBase58(), "static[{$i}]");
        }
        $this->assertCount(1, $msg->addressTableLookups);
        $this->assertSame($vec['lookup_key'], $msg->addressTableLookups[0]->accountKey->toBase58());
        $this->assertSame($vec['writable_idxs'], $msg->addressTableLookups[0]->writableIndexes);
        $this->assertSame($vec['readonly_idxs'], $msg->addressTableLookups[0]->readonlyIndexes);

        // Instruction indices must reference the COMBINED list: static + writable-lookups + readonly-lookups
        $this->assertSame(
            $vec['instruction_account_indexes'],
            $msg->compiledInstructions[0]->accountKeyIndexes,
            'accountKeyIndexes must reference the combined static+lookup list'
        );

        $this->assertSame($vec['msg_hex'], bin2hex($msg->serialize()), 'Case 3 message bytes');
    }

    // =============== Round-trip tests ==================

    public function testMessageSerializeDeserializeRoundTrip(): void
    {
        $fx = require __DIR__ . '/fixtures_v0.php';

        // Use case 3 — most complex (multi-lookup, mixed writable/readonly).
        $altKey = Keypair::fromSeed(str_repeat("\x11", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            $this->a->getPublicKey(),
            $this->b->getPublicKey(),
            $this->c->getPublicKey(),
        ]);
        $ix = new TransactionInstruction(
            SystemProgram::programId(),
            [
                AccountMeta::signerWritable($this->payer->getPublicKey()),
                AccountMeta::writable($this->a->getPublicKey()),
                AccountMeta::readonly($this->c->getPublicKey()),
            ],
            "\x42"
        );
        $original = MessageV0::compile(
            $this->payer->getPublicKey(), [$ix], self::BLOCKHASH, [$alt]
        );

        $wire = $original->serialize();
        $restored = MessageV0::deserialize($wire);

        $this->assertSame(bin2hex($wire), bin2hex($restored->serialize()), 'round-trip must be byte-identical');
        $this->assertSame($original->numRequiredSignatures, $restored->numRequiredSignatures);
        $this->assertCount(count($original->staticAccountKeys), $restored->staticAccountKeys);
        $this->assertCount(count($original->addressTableLookups), $restored->addressTableLookups);
    }

    public function testTransactionSerializeDeserializeRoundTrip(): void
    {
        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 42)],
            self::BLOCKHASH,
            []
        );
        $tx = new VersionedTransaction($msg);
        $tx->sign($this->payer);

        $wire = $tx->serialize();
        $restored = VersionedTransaction::deserialize($wire);

        $this->assertSame(bin2hex($wire), bin2hex($restored->serialize()));
        $this->assertTrue($restored->verifySignatures());
    }

    // =============== Version detection ==================

    public function testPeekVersionIdentifiesV0(): void
    {
        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            self::BLOCKHASH,
            []
        );
        $tx = new VersionedTransaction($msg);
        $tx->sign($this->payer);

        $this->assertSame(0, VersionedTransaction::peekVersion($tx->serialize()));
    }

    public function testPeekVersionIdentifiesLegacy(): void
    {
        // Build a legacy transaction and verify peekVersion returns 'legacy'.
        $legacy = \SolanaPhpSdk\Transaction\Transaction::new(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );
        $legacy->sign($this->payer);

        $this->assertSame('legacy', VersionedTransaction::peekVersion($legacy->serialize()));
    }

    public function testDeserializeRejectsLegacyMessage(): void
    {
        $legacy = \SolanaPhpSdk\Transaction\Transaction::new(
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            $this->payer->getPublicKey(),
            self::BLOCKHASH
        );
        $legacy->sign($this->payer);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('legacy');
        VersionedTransaction::deserialize($legacy->serialize());
    }

    // =============== ALT resolution ==================

    public function testResolveAddressLookupsReturnsCorrectPubkeys(): void
    {
        $altKey = Keypair::fromSeed(str_repeat("\x11", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            $this->a->getPublicKey(),
            $this->b->getPublicKey(),
            $this->c->getPublicKey(),
        ]);
        $ix = new TransactionInstruction(
            SystemProgram::programId(),
            [
                AccountMeta::signerWritable($this->payer->getPublicKey()),
                AccountMeta::writable($this->a->getPublicKey()),
                AccountMeta::readonly($this->c->getPublicKey()),
            ],
            "\x42"
        );
        $msg = MessageV0::compile(
            $this->payer->getPublicKey(), [$ix], self::BLOCKHASH, [$alt]
        );

        [$static, $writable, $readonly] = $msg->resolveAddressLookups([$alt]);
        $this->assertCount(1, $writable);
        $this->assertTrue($writable[0]->equals($this->a->getPublicKey()));
        $this->assertCount(1, $readonly);
        $this->assertTrue($readonly[0]->equals($this->c->getPublicKey()));
    }

    public function testResolveFailsWithoutMatchingAlt(): void
    {
        $altKey = Keypair::fromSeed(str_repeat("\x11", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            $this->a->getPublicKey(),
            $this->c->getPublicKey(),
        ]);
        $ix = new TransactionInstruction(
            SystemProgram::programId(),
            [
                AccountMeta::signerWritable($this->payer->getPublicKey()),
                AccountMeta::writable($this->a->getPublicKey()),
            ],
            ''
        );
        $msg = MessageV0::compile($this->payer->getPublicKey(), [$ix], self::BLOCKHASH, [$alt]);

        // Resolver called with wrong ALT list.
        $wrongAlt = new AddressLookupTableAccount(
            Keypair::fromSeed(str_repeat("\x99", 32))->getPublicKey(),
            []
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No provided ALT');
        $msg->resolveAddressLookups([$wrongAlt]);
    }

    // =============== Safety / invariant tests ==================

    public function testProgramIdNeverDrainedIntoAlt(): void
    {
        // Even if an ALT contains the System program ID, it must remain
        // in static keys because it's invoked.
        $altKey = Keypair::fromSeed(str_repeat("\x20", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            SystemProgram::programId(),   // the program — must not drain
            $this->a->getPublicKey(),
        ]);

        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            self::BLOCKHASH,
            [$alt]
        );

        // System program must appear in static keys.
        $staticBase58 = array_map(fn($k) => $k->toBase58(), $msg->staticAccountKeys);
        $this->assertContains(
            SystemProgram::PROGRAM_ID,
            $staticBase58,
            'Program IDs must stay in static keys even if they appear in an ALT'
        );
    }

    public function testSignerNeverDrainedIntoAlt(): void
    {
        // If the payer appears in an ALT, it must still go into static keys
        // because signers cannot come from lookups.
        $altKey = Keypair::fromSeed(str_repeat("\x21", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            $this->payer->getPublicKey(),
            $this->a->getPublicKey(),
        ]);

        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            self::BLOCKHASH,
            [$alt]
        );

        $staticBase58 = array_map(fn($k) => $k->toBase58(), $msg->staticAccountKeys);
        $this->assertContains(
            $this->payer->getPublicKey()->toBase58(),
            $staticBase58,
            'Signers must stay in static keys even if they appear in an ALT'
        );
        $this->assertSame(
            $this->payer->getPublicKey()->toBase58(),
            $staticBase58[0],
            'Payer must remain in position 0'
        );
    }

    public function testEmptyAltProducesNoLookupEntry(): void
    {
        // If no keys would be drained, the ALT should not appear in addressTableLookups.
        $altKey = Keypair::fromSeed(str_repeat("\x22", 32))->getPublicKey();
        $alt = new AddressLookupTableAccount($altKey, [
            Keypair::fromSeed(str_repeat("\xaa", 32))->getPublicKey(),  // unrelated pubkey
        ]);
        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            self::BLOCKHASH,
            [$alt]
        );
        $this->assertCount(0, $msg->addressTableLookups,
            'ALTs that contribute nothing must not appear in addressTableLookups');
    }

    public function testSignRejectsNonRequiredSigner(): void
    {
        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            self::BLOCKHASH,
            []
        );
        $tx = new VersionedTransaction($msg);

        $stranger = Keypair::fromSeed(str_repeat("\xfe", 32));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a required signer');
        $tx->sign($stranger);
    }

    public function testSerializeRefusesUnsignedTransaction(): void
    {
        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            self::BLOCKHASH,
            []
        );
        $tx = new VersionedTransaction($msg);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing or invalid');
        $tx->serialize();  // verifySignatures=true by default
    }

    public function testSerializeWithoutVerifyAllowsUnsigned(): void
    {
        // Needed for passing partially-signed txs to out-of-band co-signers.
        $msg = MessageV0::compile(
            $this->payer->getPublicKey(),
            [SystemProgram::transfer($this->payer->getPublicKey(), $this->a->getPublicKey(), 1)],
            self::BLOCKHASH,
            []
        );
        $tx = new VersionedTransaction($msg);

        $wire = $tx->serialize(false);
        $this->assertIsString($wire);
        $this->assertGreaterThan(0, strlen($wire));
    }

    // =============== AddressLookupTableAccount ==================

    public function testAltRejectsOver256Addresses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $addrs = [];
        for ($i = 0; $i < 257; $i++) {
            $addrs[] = Keypair::fromSeed(str_repeat(chr($i % 256), 32))->getPublicKey();
        }
        new AddressLookupTableAccount(
            Keypair::fromSeed(str_repeat("\x30", 32))->getPublicKey(),
            $addrs
        );
    }

    public function testAltIndexOfFindsPubkey(): void
    {
        $alt = new AddressLookupTableAccount(
            Keypair::fromSeed(str_repeat("\x30", 32))->getPublicKey(),
            [$this->a->getPublicKey(), $this->b->getPublicKey(), $this->c->getPublicKey()]
        );
        $this->assertSame(0, $alt->indexOf($this->a->getPublicKey()));
        $this->assertSame(1, $alt->indexOf($this->b->getPublicKey()));
        $this->assertSame(2, $alt->indexOf($this->c->getPublicKey()));
        $this->assertNull($alt->indexOf($this->payer->getPublicKey()));
    }
}
