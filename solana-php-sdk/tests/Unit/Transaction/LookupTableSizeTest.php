<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Transaction;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\SystemProgram;
use SolanaPhpSdk\Transaction\AccountMeta;
use SolanaPhpSdk\Transaction\AddressLookupTableAccount;
use SolanaPhpSdk\Transaction\MessageV0;
use SolanaPhpSdk\Transaction\Transaction;
use SolanaPhpSdk\Transaction\TransactionInstruction;
use SolanaPhpSdk\Transaction\VersionedTransaction;

/**
 * End-to-end demonstration that v0 with an ALT actually shrinks transactions.
 *
 * The whole point of Address Lookup Tables is to replace 32-byte pubkey
 * references with 1-byte indices. For a transaction touching many
 * read-only accounts (a common pattern when interacting with DeFi programs
 * that pass in reference accounts, oracles, or market state), an ALT can
 * reduce the wire size dramatically — often enough to bring a transaction
 * back under the 1232-byte MTU limit when it otherwise wouldn't fit.
 *
 * This test uses a deliberately large transaction to make the effect
 * obvious. Real-world DeFi transactions see 30-50% reductions.
 */
final class LookupTableSizeTest extends TestCase
{
    private const BLOCKHASH = 'GHtXQBsoZHVnNFa9YevAzFr17DJjgHXk3ycTKD5xD3Zi';

    public function testAltReducesTransactionSize(): void
    {
        $payer = Keypair::fromSeed(str_repeat("\x01", 32));
        $programId = new PublicKey('Memo1UhkJRfHyvLMcVucJwxXeuD728EqVDDwQDxFMNo');

        // Create 20 readonly accounts. Each takes 32 bytes in legacy / static encoding.
        $readonlyAccounts = [];
        for ($i = 0; $i < 20; $i++) {
            $readonlyAccounts[] = Keypair::fromSeed(str_repeat(chr(0x40 + $i), 32))->getPublicKey();
        }

        // One instruction that touches all 20 readonly accounts.
        $ixAccounts = [AccountMeta::signerWritable($payer->getPublicKey())];
        foreach ($readonlyAccounts as $pk) {
            $ixAccounts[] = AccountMeta::readonly($pk);
        }
        $ix = new TransactionInstruction($programId, $ixAccounts, 'test');

        // ---- Legacy transaction baseline ----
        $legacy = Transaction::new([$ix], $payer->getPublicKey(), self::BLOCKHASH);
        $legacy->sign($payer);
        $legacyBytes = strlen($legacy->serialize());

        // ---- v0 transaction WITHOUT an ALT ----
        // Should be essentially the same size as legacy plus 3 bytes (version prefix
        // byte + compactU16 for empty lookup list).
        $msgNoAlt = MessageV0::compile($payer->getPublicKey(), [$ix], self::BLOCKHASH, []);
        $txNoAlt = new VersionedTransaction($msgNoAlt);
        $txNoAlt->sign($payer);
        $noAltBytes = strlen($txNoAlt->serialize());

        // ---- v0 transaction WITH an ALT holding all 20 readonly accounts ----
        $alt = new AddressLookupTableAccount(
            Keypair::fromSeed(str_repeat("\xAA", 32))->getPublicKey(),
            $readonlyAccounts
        );
        $msgWithAlt = MessageV0::compile($payer->getPublicKey(), [$ix], self::BLOCKHASH, [$alt]);
        $txWithAlt = new VersionedTransaction($msgWithAlt);
        $txWithAlt->sign($payer);
        $withAltBytes = strlen($txWithAlt->serialize());

        // The ALT version should be substantially smaller.
        // Savings formula: 20 accounts × (32 - 1) bytes = 620 bytes saved,
        // minus overhead of the lookup entry (~35 bytes).
        $this->assertLessThan(
            $noAltBytes - 500,
            $withAltBytes,
            "ALT should save well over 500 bytes vs. no-ALT v0 (no-ALT={$noAltBytes}, withALT={$withAltBytes})"
        );

        // And legacy should be almost identical to no-ALT v0.
        $this->assertLessThan(
            $legacyBytes + 10,
            $noAltBytes,
            'v0 without an ALT should be roughly the same size as legacy'
        );

        // Sanity: the ALT version resolves back to the same readonly account list.
        [, , $readonly] = $msgWithAlt->resolveAddressLookups([$alt]);
        $this->assertCount(20, $readonly);

        // And it fits under the MTU.
        $this->assertLessThanOrEqual(1232, $withAltBytes, 'Must fit in the transaction MTU');
    }

    public function testV0RoundTripsAlongsideLegacyInSameWorkflow(): void
    {
        // Simulate a client that may receive either legacy or v0 transactions
        // off the wire and needs to route them correctly.
        $payer = Keypair::fromSeed(str_repeat("\x42", 32));
        $recipient = Keypair::fromSeed(str_repeat("\x43", 32))->getPublicKey();

        // Build one of each.
        $legacyTx = Transaction::new(
            [SystemProgram::transfer($payer->getPublicKey(), $recipient, 100)],
            $payer->getPublicKey(),
            self::BLOCKHASH
        );
        $legacyTx->sign($payer);
        $legacyWire = $legacyTx->serialize();

        $v0Msg = MessageV0::compile(
            $payer->getPublicKey(),
            [SystemProgram::transfer($payer->getPublicKey(), $recipient, 100)],
            self::BLOCKHASH,
            []
        );
        $v0Tx = new VersionedTransaction($v0Msg);
        $v0Tx->sign($payer);
        $v0Wire = $v0Tx->serialize();

        // The router pattern: inspect first, then dispatch.
        $this->assertSame('legacy', VersionedTransaction::peekVersion($legacyWire));
        $this->assertSame(0, VersionedTransaction::peekVersion($v0Wire));

        // And each class refuses bytes of the wrong kind.
        $restoredLegacy = Transaction::deserialize($legacyWire);
        $restoredV0 = VersionedTransaction::deserialize($v0Wire);
        $this->assertTrue($restoredLegacy->verifySignatures());
        $this->assertTrue($restoredV0->verifySignatures());
    }
}
