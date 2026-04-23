<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Programs;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\AssociatedTokenProgram;
use SolanaPhpSdk\Programs\ComputeBudgetProgram;
use SolanaPhpSdk\Programs\MemoProgram;
use SolanaPhpSdk\Programs\PaymentBuilder;
use SolanaPhpSdk\Programs\SystemProgram;
use SolanaPhpSdk\Programs\TokenProgram;
use SolanaPhpSdk\Rpc\Fee\FeeEstimate;
use SolanaPhpSdk\Rpc\Fee\FeeEstimator;
use SolanaPhpSdk\Rpc\Fee\PriorityLevel;
use SolanaPhpSdk\Rpc\RpcClient;
use SolanaPhpSdk\Tests\Unit\Rpc\MockHttpClient;
use SolanaPhpSdk\Transaction\Transaction;
use SolanaPhpSdk\Util\Base58;

/**
 * Tests for the high-level PaymentBuilder.
 *
 * Since PaymentBuilder is a composition layer over primitives that are
 * already heavily tested, these tests focus on:
 *   - Instruction assembly (right programs, in the right order)
 *   - RPC side-effect boundaries (which methods make network calls)
 *   - Conditional behavior (ATA auto-create on missing, skip when present)
 *   - Validation of required fields
 *   - SOL vs. SPL branching
 */
final class PaymentBuilderTest extends TestCase
{
    private const USDC = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    private const BLOCKHASH = 'GHtXQBsoZHVnNFa9YevAzFr17DJjgHXk3ycTKD5xD3Zi';

    private Keypair $customer;
    private PublicKey $merchant;

    protected function setUp(): void
    {
        $this->customer = Keypair::fromSeed(str_repeat("\xaa", 32));
        $this->merchant = Keypair::fromSeed(str_repeat("\xbb", 32))->getPublicKey();
    }

    private function newRpc(): array
    {
        $mock = new MockHttpClient();
        return [new RpcClient('https://example.test/rpc', $mock), $mock];
    }

    /**
     * Resolve a Transaction's compiled instructions into program IDs
     * (in input order). Compiled instructions store only a programIdIndex
     * into accountKeys, so we dereference each one.
     *
     * @return array<int, string>
     */
    private static function programIdsOf(Transaction $tx): array
    {
        $msg = $tx->message;
        $out = [];
        foreach ($msg->instructions as $ci) {
            $out[] = $msg->accountKeys[$ci['programIdIndex']]->toBase58();
        }
        return $out;
    }

    private static function instructionData(Transaction $tx, int $index): string
    {
        return $tx->message->instructions[$index]['data'];
    }

    /**
     * Resolve the Nth instruction's account references back to PublicKeys.
     *
     * @return array<int, PublicKey>
     */
    private static function instructionAccounts(Transaction $tx, int $index): array
    {
        $msg = $tx->message;
        $accounts = [];
        foreach ($msg->instructions[$index]['accounts'] as $accIdx) {
            $accounts[] = $msg->accountKeys[$accIdx];
        }
        return $accounts;
    }

    // ----- SPL token happy path ------------------------------------------

    public function testSplTokenBuildProducesExpectedInstructionOrder(): void
    {
        [$rpc, ] = $this->newRpc();
        $tx = PaymentBuilder::splToken($rpc, new PublicKey(self::USDC), 6)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(1_000_000)
            ->blockhash(self::BLOCKHASH)
            ->build();

        $this->assertSame([
            ComputeBudgetProgram::PROGRAM_ID,
            ComputeBudgetProgram::PROGRAM_ID,
            TokenProgram::PROGRAM_ID,
        ], self::programIdsOf($tx), 'Default SPL flow: [CU limit, CU price, transferChecked]');

        // cu-limit data: 0x02 + u32 LE 200_000
        $this->assertSame('02400d0300', bin2hex(self::instructionData($tx, 0)));
        // cu-price data: 0x03 + u64 LE 1_000
        $this->assertSame('03e803000000000000', bin2hex(self::instructionData($tx, 1)));
    }

    public function testSplTokenWithCreateIdempotentAndMemoAndReference(): void
    {
        [$rpc, ] = $this->newRpc();
        $ref = Keypair::generate()->getPublicKey();

        $tx = PaymentBuilder::splToken($rpc, new PublicKey(self::USDC), 6)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(10_000_000)
            ->createIdempotent()
            ->addReference($ref)
            ->memo('order:42')
            ->blockhash(self::BLOCKHASH)
            ->build();

        $this->assertSame([
            ComputeBudgetProgram::PROGRAM_ID,
            ComputeBudgetProgram::PROGRAM_ID,
            AssociatedTokenProgram::PROGRAM_ID,
            TokenProgram::PROGRAM_ID,
            MemoProgram::PROGRAM_ID_V2,
        ], self::programIdsOf($tx));

        // Reference must appear among the transferChecked accounts.
        $transferAccounts = self::instructionAccounts($tx, 3);
        $found = false;
        foreach ($transferAccounts as $pk) {
            if ($pk->equals($ref)) { $found = true; break; }
        }
        $this->assertTrue($found, 'Reference must be attached to transferChecked account list');

        $this->assertSame('order:42', self::instructionData($tx, 4));
    }

    public function testBuildAndSignReturnsSignedTransaction(): void
    {
        [$rpc, ] = $this->newRpc();
        $tx = PaymentBuilder::splToken($rpc, new PublicKey(self::USDC), 6)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(1_000_000)
            ->blockhash(self::BLOCKHASH)
            ->buildAndSign();

        $this->assertTrue($tx->verifySignatures());
        $this->assertLessThan(1232, strlen($tx->serialize()));
    }

    // ----- SOL happy path -------------------------------------------------

    public function testSolBuildProducesSystemTransfer(): void
    {
        [$rpc, ] = $this->newRpc();
        $tx = PaymentBuilder::sol($rpc)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(500_000_000)
            ->blockhash(self::BLOCKHASH)
            ->build();

        $this->assertSame([
            ComputeBudgetProgram::PROGRAM_ID,
            ComputeBudgetProgram::PROGRAM_ID,
            SystemProgram::PROGRAM_ID,
        ], self::programIdsOf($tx));
    }

    public function testSolCreateIdempotentIsSilentNoOp(): void
    {
        [$rpc, ] = $this->newRpc();
        $tx = PaymentBuilder::sol($rpc)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(100)
            ->createIdempotent()
            ->blockhash(self::BLOCKHASH)
            ->build();

        foreach (self::programIdsOf($tx) as $pid) {
            $this->assertNotSame(AssociatedTokenProgram::PROGRAM_ID, $pid);
        }
    }

    // ----- RPC-backed methods --------------------------------------------

    public function testWithFreshBlockhashFetchesFromRpc(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getLatestBlockhash')->respond([
            'context' => ['slot' => 1],
            'value' => [
                'blockhash' => self::BLOCKHASH,
                'lastValidBlockHeight' => 100,
            ],
        ]);

        $tx = PaymentBuilder::sol($rpc)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(100)
            ->withFreshBlockhash()
            ->build();

        // Message stores blockhash as raw 32 bytes — compare Base58-decoded form.
        $this->assertSame(
            bin2hex(Base58::decode(self::BLOCKHASH)),
            bin2hex($tx->message->recentBlockhash)
        );
        $this->assertSame('getLatestBlockhash', $mock->requests[0]['method']);
    }

    public function testEnsureRecipientAtaSkipsCreateWhenAtaExists(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getAccountInfo')->respond([
            'context' => ['slot' => 1],
            'value' => [
                'lamports' => 2_039_280,
                'owner' => TokenProgram::PROGRAM_ID,
                'data' => [base64_encode(str_repeat("\x00", 165)), 'base64'],
                'executable' => false,
                'rentEpoch' => 0,
            ],
        ]);

        $tx = PaymentBuilder::splToken($rpc, new PublicKey(self::USDC), 6)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(1_000_000)
            ->ensureRecipientAta()
            ->blockhash(self::BLOCKHASH)
            ->build();

        foreach (self::programIdsOf($tx) as $pid) {
            $this->assertNotSame(AssociatedTokenProgram::PROGRAM_ID, $pid);
        }
    }

    public function testEnsureRecipientAtaIncludesCreateWhenAtaMissing(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getAccountInfo')->respond(['value' => null]);

        $tx = PaymentBuilder::splToken($rpc, new PublicKey(self::USDC), 6)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(1_000_000)
            ->ensureRecipientAta()
            ->blockhash(self::BLOCKHASH)
            ->build();

        $this->assertContains(
            AssociatedTokenProgram::PROGRAM_ID,
            self::programIdsOf($tx),
            'Missing ATA must trigger createIdempotent'
        );
    }

    public function testWithFeeEstimateSetsComputeUnitPrice(): void
    {
        [$rpc, ] = $this->newRpc();
        $stub = new class implements FeeEstimator {
            public function estimate(array $writableAccounts = []): FeeEstimate
            {
                return new FeeEstimate(1, 10, 100, 1000, 10000, 'stub');
            }
            public function estimateLevel(array $writableAccounts, string $level): int
            {
                return ['min'=>1,'low'=>10,'medium'=>100,'high'=>1000,'veryHigh'=>10000][$level];
            }
        };

        $tx = PaymentBuilder::splToken($rpc, new PublicKey(self::USDC), 6)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(1_000_000)
            ->withFeeEstimate($stub, PriorityLevel::HIGH)
            ->blockhash(self::BLOCKHASH)
            ->build();

        $expectedHex = '03' . bin2hex(pack('P', 1000));
        $this->assertSame($expectedHex, bin2hex(self::instructionData($tx, 1)));
    }

    // ----- Validation / error paths --------------------------------------

    public function testBuildFailsWithoutFrom(): void
    {
        [$rpc, ] = $this->newRpc();
        $this->expectException(InvalidArgumentException::class);
        PaymentBuilder::sol($rpc)->to($this->merchant)->amount(1)->blockhash(self::BLOCKHASH)->build();
    }

    public function testBuildFailsWithoutTo(): void
    {
        [$rpc, ] = $this->newRpc();
        $this->expectException(InvalidArgumentException::class);
        PaymentBuilder::sol($rpc)->from($this->customer)->amount(1)->blockhash(self::BLOCKHASH)->build();
    }

    public function testBuildFailsWithoutAmount(): void
    {
        [$rpc, ] = $this->newRpc();
        $this->expectException(InvalidArgumentException::class);
        PaymentBuilder::sol($rpc)->from($this->customer)->to($this->merchant)->blockhash(self::BLOCKHASH)->build();
    }

    public function testBuildFailsWithoutBlockhash(): void
    {
        [$rpc, ] = $this->newRpc();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('blockhash');
        PaymentBuilder::sol($rpc)->from($this->customer)->to($this->merchant)->amount(1)->build();
    }

    public function testBuildAndSignRequiresKeypair(): void
    {
        [$rpc, ] = $this->newRpc();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Keypair');
        PaymentBuilder::sol($rpc)
            ->from($this->customer->getPublicKey())
            ->to($this->merchant)->amount(1)->blockhash(self::BLOCKHASH)->buildAndSign();
    }

    public function testBuildWithPublicKeyFromWorksButUnsigned(): void
    {
        [$rpc, ] = $this->newRpc();
        $tx = PaymentBuilder::sol($rpc)
            ->from($this->customer->getPublicKey())
            ->to($this->merchant)->amount(1)->blockhash(self::BLOCKHASH)->build();
        $this->assertFalse($tx->verifySignatures());
    }

    public function testAmountRejectsNegative(): void
    {
        [$rpc, ] = $this->newRpc();
        $this->expectException(InvalidArgumentException::class);
        PaymentBuilder::sol($rpc)->amount(-5);
    }

    public function testAmountAcceptsNumericString(): void
    {
        [$rpc, ] = $this->newRpc();
        $tx = PaymentBuilder::sol($rpc)
            ->from($this->customer)->to($this->merchant)
            ->amount('18446744073709551615')
            ->blockhash(self::BLOCKHASH)->build();

        // Transfer instruction is position 2. Data = u32 LE disc (2) + u64 LE amount.
        $this->assertStringEndsWith('ffffffffffffffff', bin2hex(self::instructionData($tx, 2)));
    }

    // ----- Token-2022 -----------------------------------------------------

    public function testToken2022OverrideFlowsThroughToAllInstructions(): void
    {
        [$rpc, ] = $this->newRpc();
        $tx = PaymentBuilder::splToken(
                $rpc, new PublicKey(self::USDC), 6, TokenProgram::token2022ProgramId()
            )
            ->from($this->customer)->to($this->merchant)
            ->amount(1_000_000)->createIdempotent()
            ->blockhash(self::BLOCKHASH)->build();

        $this->assertSame(TokenProgram::TOKEN_2022_PROGRAM_ID, self::programIdsOf($tx)[3],
            'transferChecked must target Token-2022');

        $createAccounts = self::instructionAccounts($tx, 2);
        $this->assertSame(TokenProgram::TOKEN_2022_PROGRAM_ID, $createAccounts[5]->toBase58(),
            'createIdempotent tokenProgram slot must be Token-2022');
    }

    // ----- End-to-end full USDC flow -------------------------------------

    public function testFullUsdcPaymentEndToEnd(): void
    {
        [$rpc, $mock] = $this->newRpc();
        $mock->on('getAccountInfo')->respond(['value' => null]);
        $mock->on('getLatestBlockhash')->respond([
            'context' => ['slot' => 100],
            'value' => ['blockhash' => self::BLOCKHASH, 'lastValidBlockHeight' => 200],
        ]);

        $ref = Keypair::generate()->getPublicKey();

        $tx = PaymentBuilder::splToken($rpc, new PublicKey(self::USDC), 6)
            ->from($this->customer)
            ->to($this->merchant)
            ->amount(29_990_000)
            ->ensureRecipientAta()
            ->addReference($ref)
            ->memo('order:OC-2025-00042')
            ->withFreshBlockhash()
            ->buildAndSign();

        $this->assertTrue($tx->verifySignatures());
        $this->assertLessThan(1232, strlen($tx->serialize()));

        $this->assertSame([
            ComputeBudgetProgram::PROGRAM_ID,
            ComputeBudgetProgram::PROGRAM_ID,
            AssociatedTokenProgram::PROGRAM_ID,
            TokenProgram::PROGRAM_ID,
            MemoProgram::PROGRAM_ID_V2,
        ], self::programIdsOf($tx));
    }
}
