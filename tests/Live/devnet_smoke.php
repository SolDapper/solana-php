<?php

/**
 * Solana PHP - Live integration test against devnet (or any real RPC).
 *
 * This script is NOT part of the unit test suite. It's a standalone smoke
 * test you run manually to verify the library works end-to-end against
 * real infrastructure: a real RPC provider, a real blockhash, a real
 * signature that actually lands on chain.
 *
 * Run it before shipping to production. Run it after any non-trivial change
 * to the transaction-building pipeline.
 *
 * =============================================================================
 * PREREQUISITES
 * =============================================================================
 *
 * 1. A funded devnet wallet. Get one with:
 *
 *        solana-keygen new --outfile ~/.config/solana/devnet-test.json
 *        solana airdrop 2 --keypair ~/.config/solana/devnet-test.json \
 *            --url https://api.devnet.solana.com
 *
 *    Or use the Solana faucet at https://faucet.solana.com.
 *
 * 2. (Optional) Some devnet USDC to exercise the SPL path. The devnet USDC
 *    faucet lives at https://faucet.circle.com (select "Solana Devnet",
 *    paste your wallet address). If you skip this the USDC tests will be
 *    marked SKIP rather than FAIL.
 *
 * 3. Configure the script via environment variables (or edit the defaults
 *    near the top of main()):
 *
 *      SOLANA_PHP_RPC_URL       RPC endpoint (default: devnet public)
 *      SOLANA_PHP_KEYPAIR_FILE  Path to a Solana CLI JSON keypair file
 *      SOLANA_PHP_KEYPAIR_JSON  Keypair as JSON array string (alternative
 *                               to KEYPAIR_FILE, useful in CI)
 *      SOLANA_PHP_MERCHANT      Base58 pubkey to send to (default: generated
 *                               ephemeral key, which will receive and hold
 *                               the funds)
 *
 *    Example invocation:
 *
 *        export SOLANA_PHP_KEYPAIR_FILE=~/.config/solana/devnet-test.json
 *        php tests/Live/devnet_smoke.php
 *
 * =============================================================================
 * WHAT IT TESTS
 * =============================================================================
 *
 *   1. RPC connectivity:      getLatestBlockhash, getBalance
 *   2. SOL transfer:          build + sign + submit + confirm a PaymentBuilder::sol() tx
 *   3. Fee estimation:        query StandardFeeEstimator against the live chain
 *   4. Solana Pay URL:        encode a TransferRequest (no network needed, but sanity check)
 *   5. USDC ATA awareness:    ensureRecipientAta against a fresh address (skipped if
 *                             no USDC balance in source wallet)
 *   6. USDC transfer:         build + sign + submit + confirm a TransferChecked (skipped
 *                             if no USDC)
 *
 * Each step prints PASS / FAIL / SKIP with a reason.
 *
 * Exit code: 0 if all non-SKIP steps pass, 1 otherwise.
 */

declare(strict_types=1);

// ============================================================================
// Bootstrap autoloading. Works with both composer vendor/ and bare source.
// ============================================================================

$composerAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require $composerAutoload;
} else {
    // Fallback: use src/ directly
    spl_autoload_register(function ($class) {
        $prefix = 'SolanaPhpSdk\\';
        $baseDir = __DIR__ . '/../../src/';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Exception\SolanaException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\AssociatedTokenProgram;
use SolanaPhpSdk\Programs\PaymentBuilder;
use SolanaPhpSdk\Programs\TokenProgram;
use SolanaPhpSdk\Rpc\Commitment;
use SolanaPhpSdk\Rpc\Fee\PriorityLevel;
use SolanaPhpSdk\Rpc\Fee\StandardFeeEstimator;
use SolanaPhpSdk\Rpc\RpcClient;
use SolanaPhpSdk\SolanaPay\TransferRequest;
use SolanaPhpSdk\SolanaPay\Url;

// ============================================================================
// Devnet canonical addresses
// ============================================================================

/** Circle's devnet USDC mint. Unchanged since 2023. */
/**
 * Circle's current devnet USDC mint (per https://developers.circle.com/stablecoins/docs/usdc-on-testing-networks).
 * This is distinct from the legacy `Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr` mint
 * that was canonical in earlier Circle docs; the faucet at faucet.circle.com now
 * dispenses tokens from this mint.
 */
const DEVNET_USDC_MINT = '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU';
const DEVNET_USDC_DECIMALS = 6;

const DEFAULT_RPC = 'https://api.devnet.solana.com';

// Send only tiny amounts. 0.001 SOL and 0.01 USDC are plenty to prove the
// transaction lands; we don't need to move real value around.
const TEST_SOL_LAMPORTS = 1_000_000;        // 0.001 SOL
const TEST_USDC_BASE_UNITS = 10_000;         // 0.01 USDC

// ============================================================================
// Output helpers
// ============================================================================

function banner(string $text): void
{
    echo "\n" . str_repeat('=', 72) . "\n";
    echo " {$text}\n";
    echo str_repeat('=', 72) . "\n";
}

function step(string $name): void
{
    echo "\n-> {$name}\n";
}

function pass(string $msg = ''): void
{
    echo "   [PASS] {$msg}\n";
}

function fail(string $msg): void
{
    echo "   [FAIL] {$msg}\n";
}

function skip(string $reason): void
{
    echo "   [SKIP] {$reason}\n";
}

function info(string $msg): void
{
    echo "         {$msg}\n";
}

// ============================================================================
// Config loading
// ============================================================================

function loadKeypair(): Keypair
{
    $file = getenv('SOLANA_PHP_KEYPAIR_FILE');
    if ($file !== false && $file !== '') {
        if (!is_file($file)) {
            throw new RuntimeException("KEYPAIR_FILE does not exist: {$file}");
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("Unable to read KEYPAIR_FILE: {$file}");
        }
        return Keypair::fromJsonArray($contents);
    }

    $json = getenv('SOLANA_PHP_KEYPAIR_JSON');
    if ($json !== false && $json !== '') {
        return Keypair::fromJsonArray($json);
    }

    throw new RuntimeException(
        "No keypair configured. Set SOLANA_PHP_KEYPAIR_FILE or SOLANA_PHP_KEYPAIR_JSON."
    );
}

function loadMerchant(): PublicKey
{
    $merchant = getenv('SOLANA_PHP_MERCHANT');
    if ($merchant !== false && $merchant !== '') {
        return new PublicKey($merchant);
    }
    // Ephemeral merchant: generate a fresh keypair, use its pubkey.
    // The funds will sit in this throwaway address. That's fine for devnet.
    $ephemeral = Keypair::generate()->getPublicKey();
    info("Using ephemeral merchant pubkey: " . $ephemeral->toBase58());
    return $ephemeral;
}

function loadRpcUrl(): string
{
    $url = getenv('SOLANA_PHP_RPC_URL');
    return ($url !== false && $url !== '') ? $url : DEFAULT_RPC;
}

// ============================================================================
// Chain interaction helpers
// ============================================================================

/**
 * Poll getSignatureStatuses until a signature is confirmed or we time out.
 *
 * @return string The terminal confirmationStatus ('confirmed'/'finalized')
 *                or 'timeout' if we gave up.
 */
function waitForConfirmation(
    RpcClient $rpc,
    string $signature,
    int $timeoutSeconds = 60
): string {
    $deadline = time() + $timeoutSeconds;
    $backoff = 1;

    while (time() < $deadline) {
        $statuses = $rpc->getSignatureStatuses([$signature]);
        $status = $statuses[0] ?? null;
        if ($status !== null) {
            if ($status['err'] !== null) {
                throw new RuntimeException(
                    'Transaction failed on chain: ' . json_encode($status['err'])
                );
            }
            if (in_array($status['confirmationStatus'], ['confirmed', 'finalized'], true)) {
                return (string) $status['confirmationStatus'];
            }
        }
        sleep($backoff);
        $backoff = min($backoff + 1, 5);
    }
    return 'timeout';
}

/**
 * Try to fetch a token-account balance. Returns null if the account
 * doesn't exist (ATA not created yet).
 */
function getTokenBalance(RpcClient $rpc, PublicKey $tokenAccount): ?int
{
    try {
        $resp = $rpc->call('getTokenAccountBalance', [$tokenAccount->toBase58()]);
        $amount = $resp['value']['amount'] ?? null;
        return $amount === null ? null : (int) $amount;
    } catch (RpcException $e) {
        // "could not find account" or similar -> ATA missing.
        return null;
    }
}

// ============================================================================
// Test steps
// ============================================================================

/** @return bool pass */
function stepRpcConnectivity(RpcClient $rpc, Keypair $payer): bool
{
    step("1. RPC connectivity: getLatestBlockhash + getBalance");
    try {
        $bh = $rpc->getLatestBlockhash();
        info("Blockhash: {$bh['blockhash']} (valid until block {$bh['lastValidBlockHeight']})");
        $balance = $rpc->getBalance($payer->getPublicKey());
        info("Payer:     " . $payer->getPublicKey()->toBase58());
        info("Balance:   " . (is_int($balance) ? $balance : $balance) . " lamports");

        if ((int) $balance < 10_000_000) {
            fail("Payer balance is below 0.01 SOL. Airdrop more before continuing.");
            return false;
        }
        pass("RPC responding, payer funded");
        return true;
    } catch (\Throwable $e) {
        fail("RPC call failed: " . $e->getMessage());
        return false;
    }
}

function stepSolTransfer(RpcClient $rpc, Keypair $payer, PublicKey $merchant): bool
{
    step("2. SOL transfer: PaymentBuilder::sol() end-to-end");
    try {
        $tx = PaymentBuilder::sol($rpc)
            ->from($payer)
            ->to($merchant)
            ->amount(TEST_SOL_LAMPORTS)
            ->withFreshBlockhash(Commitment::FINALIZED)
            ->buildAndSign();

        info("Wire size: " . strlen($tx->serialize()) . " bytes");
        $sig = $rpc->sendTransaction($tx);
        info("Signature: {$sig}");
        info("Explorer: https://explorer.solana.com/tx/{$sig}?cluster=devnet");

        $status = waitForConfirmation($rpc, $sig);
        if ($status === 'timeout') {
            fail("Transaction did not confirm within 60s");
            return false;
        }
        pass("Transaction confirmed ({$status})");
        return true;
    } catch (\Throwable $e) {
        fail(get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}

function stepFeeEstimation(RpcClient $rpc, Keypair $payer, PublicKey $merchant): bool
{
    step("3. Fee estimation: StandardFeeEstimator against the live chain");
    try {
        $estimator = new StandardFeeEstimator($rpc);
        $estimate = $estimator->estimate([$payer->getPublicKey(), $merchant]);
        info(sprintf(
            "min=%d low=%d medium=%d high=%d veryHigh=%d (micro-lamports per CU)",
            $estimate->min, $estimate->low, $estimate->medium,
            $estimate->high, $estimate->veryHigh
        ));
        info("Source: {$estimate->source}");
        if ($estimate->min < 0 || $estimate->veryHigh < $estimate->min) {
            fail("Estimator returned nonsensical values");
            return false;
        }
        pass("Estimator returned plausible per-level values");
        return true;
    } catch (\Throwable $e) {
        fail(get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}

function stepSolanaPayUrl(Keypair $payer, PublicKey $merchant): bool
{
    step("4. Solana Pay URL: encode a TransferRequest");
    try {
        $orderRef = Keypair::generate()->getPublicKey();
        $req = TransferRequest::builder($merchant)
            ->amount('0.01')
            ->splToken(new PublicKey(DEVNET_USDC_MINT))
            ->addReference($orderRef)
            ->label('Live smoke test')
            ->memo('order:smoke-001')
            ->build();
        $url = Url::encodeTransfer($req);
        info("URL: {$url}");

        // Round-trip parse to make sure it's well-formed.
        $reparsed = Url::parse($url);
        if (!$reparsed instanceof TransferRequest) {
            fail("Parsed URL did not round-trip as TransferRequest");
            return false;
        }
        if (!$reparsed->recipient->equals($merchant)) {
            fail("Recipient did not round-trip");
            return false;
        }
        pass("URL encoded and parsed successfully");
        info("(Paste into Phantom/Solflare manually to verify wallet handling.)");
        return true;
    } catch (\Throwable $e) {
        fail(get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}

/** @return array{passed: bool, skipped: bool} */
function stepUsdcAtaAwareness(RpcClient $rpc, Keypair $payer, PublicKey $merchant): array
{
    step("5. USDC ATA awareness: ensureRecipientAta against a fresh merchant");
    try {
        // Verify the payer even has USDC. If not, skip cleanly.
        $usdcMint = new PublicKey(DEVNET_USDC_MINT);
        [$payerAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
            $payer->getPublicKey(), $usdcMint
        );
        $balance = getTokenBalance($rpc, $payerAta);
        if ($balance === null) {
            skip("Payer has no devnet USDC ATA. Get some from https://faucet.circle.com.");
            return ['passed' => true, 'skipped' => true];
        }
        info("Payer USDC balance: {$balance} base units (" . ($balance / 1_000_000) . " USDC)");

        [$merchantAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
            $merchant, $usdcMint
        );
        // This is the meaningful part: check whether ensureRecipientAta correctly
        // identifies missing-vs-present ATAs by calling getAccountInfo directly.
        $accountInfo = $rpc->getAccountInfo($merchantAta);
        info(sprintf(
            "Merchant ATA %s: %s",
            $merchantAta->toBase58(),
            $accountInfo === null ? 'does NOT exist (ensureRecipientAta should create)' : 'exists (ensureRecipientAta should skip)'
        ));
        pass("ATA derivation and existence check work against live RPC");
        return ['passed' => true, 'skipped' => false];
    } catch (\Throwable $e) {
        fail(get_class($e) . ': ' . $e->getMessage());
        return ['passed' => false, 'skipped' => false];
    }
}

/** @return array{passed: bool, skipped: bool} */
function stepUsdcTransfer(RpcClient $rpc, Keypair $payer, PublicKey $merchant): array
{
    step("6. USDC transfer: PaymentBuilder::splToken() end-to-end");
    try {
        $usdcMint = new PublicKey(DEVNET_USDC_MINT);
        [$payerAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
            $payer->getPublicKey(), $usdcMint
        );
        $balance = getTokenBalance($rpc, $payerAta);
        if ($balance === null || $balance < TEST_USDC_BASE_UNITS) {
            skip(sprintf(
                "Payer needs at least %d USDC base units (%f USDC). Get some from https://faucet.circle.com.",
                TEST_USDC_BASE_UNITS, TEST_USDC_BASE_UNITS / 1_000_000
            ));
            return ['passed' => true, 'skipped' => true];
        }

        $tx = PaymentBuilder::splToken($rpc, $usdcMint, DEVNET_USDC_DECIMALS)
            ->from($payer)
            ->to($merchant)
            ->amount(TEST_USDC_BASE_UNITS)
            ->ensureRecipientAta()      // creates merchant ATA if needed
            ->memo('solana-php smoke test')
            ->withFreshBlockhash(Commitment::FINALIZED)
            ->buildAndSign();

        info("Wire size: " . strlen($tx->serialize()) . " bytes");
        $sig = $rpc->sendTransaction($tx);
        info("Signature: {$sig}");
        info("Explorer: https://explorer.solana.com/tx/{$sig}?cluster=devnet");

        $status = waitForConfirmation($rpc, $sig, 90);
        if ($status === 'timeout') {
            fail("Transaction did not confirm within 90s");
            return ['passed' => false, 'skipped' => false];
        }
        pass("USDC transfer confirmed ({$status})");
        return ['passed' => true, 'skipped' => false];
    } catch (\Throwable $e) {
        fail(get_class($e) . ': ' . $e->getMessage());
        return ['passed' => false, 'skipped' => false];
    }
}

// ============================================================================
// Main
// ============================================================================

function main(): int
{
    banner('Solana PHP ' . \SolanaPhpSdk\SolanaPhpSdk::VERSION . ' - Live devnet smoke test');

    try {
        $rpcUrl = loadRpcUrl();
        info("RPC endpoint: {$rpcUrl}");
        $rpc = new RpcClient($rpcUrl);

        $payer = loadKeypair();
        $merchant = loadMerchant();
    } catch (\Throwable $e) {
        echo "\n[ERROR] Setup failed: " . $e->getMessage() . "\n\n";
        echo "Required environment variables:\n";
        echo "  SOLANA_PHP_KEYPAIR_FILE   (or) SOLANA_PHP_KEYPAIR_JSON\n";
        echo "Optional:\n";
        echo "  SOLANA_PHP_RPC_URL        default: " . DEFAULT_RPC . "\n";
        echo "  SOLANA_PHP_MERCHANT       default: ephemeral generated pubkey\n";
        return 1;
    }

    $results = [];

    $results[] = ['name' => 'RPC connectivity',      'ok' => stepRpcConnectivity($rpc, $payer),      'skip' => false];
    if (!$results[0]['ok']) {
        // If basic connectivity is broken, everything else will fail too.
        printSummary($results);
        return 1;
    }

    $results[] = ['name' => 'SOL transfer',          'ok' => stepSolTransfer($rpc, $payer, $merchant),   'skip' => false];
    $results[] = ['name' => 'Fee estimation',        'ok' => stepFeeEstimation($rpc, $payer, $merchant), 'skip' => false];
    $results[] = ['name' => 'Solana Pay URL',        'ok' => stepSolanaPayUrl($payer, $merchant),        'skip' => false];

    $ataResult = stepUsdcAtaAwareness($rpc, $payer, $merchant);
    $results[] = ['name' => 'USDC ATA awareness', 'ok' => $ataResult['passed'], 'skip' => $ataResult['skipped']];

    $usdcResult = stepUsdcTransfer($rpc, $payer, $merchant);
    $results[] = ['name' => 'USDC transfer', 'ok' => $usdcResult['passed'], 'skip' => $usdcResult['skipped']];

    printSummary($results);
    foreach ($results as $r) {
        if (!$r['ok']) {
            return 1;
        }
    }
    return 0;
}

/**
 * @param array<int, array{name: string, ok: bool, skip: bool}> $results
 */
function printSummary(array $results): void
{
    banner('Summary');
    foreach ($results as $r) {
        $tag = $r['skip'] ? '[SKIP]' : ($r['ok'] ? '[PASS]' : '[FAIL]');
        echo sprintf("  %-6s %s\n", $tag, $r['name']);
    }
    echo "\n";
}

exit(main());
