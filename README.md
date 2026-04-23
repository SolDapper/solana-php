# Solana PHP

A framework-agnostic PHP library for building Solana transactions, instructions, and integrating Solana payments into PHP applications.

**Status:** All planned features are implemented and every wire format is byte-for-byte validated against the canonical JavaScript and Rust reference implementations (`@solana/web3.js`, `@solana/spl-token`, `@solana/pay`, `borsh-rs`). 307 unit tests, 1029 assertions.

**Not yet validated:** end-to-end against a live RPC provider or a live wallet. The byte-for-byte parity is strong evidence that produced transactions will behave identically to web3.js output (same bytes in, same chain behavior out), but no transaction built by this library has yet been submitted to devnet or mainnet, and no Solana Pay URL has been tested against a real wallet app. Treat the library as beta until that live-fire testing is done. Bug reports from real-world integration are very welcome.

## Requirements

- PHP 8.0 or higher
- `ext-sodium` (included in PHP by default; provides Ed25519 signing)
- `ext-mbstring`
- `ext-gmp` **strongly recommended** (required for PDA derivation, preferred for Base58)
- `ext-bcmath` as an optional Base58 fallback when GMP is unavailable

## Installation

### With Composer (recommended)

```bash
composer require solana-php/solana-sdk
```

### Without Composer

Solana PHP uses PSR-4 autoloading but doesn't actually require Composer at runtime - you can drop the `src/` directory into your project and wire up a minimal autoloader yourself. This is useful for shared hosting without shell access, bundled CMS extensions that ship self-contained, or locked-down enterprise environments.

1. Download the source (git clone, tarball from releases, or copy the `src/` directory out of an existing install).
2. Register an autoloader that maps the `SolanaPhpSdk\` namespace to wherever you placed `src/`:

   ```php
   spl_autoload_register(function ($class) {
       $prefix = 'SolanaPhpSdk\\';
       $baseDir = __DIR__ . '/path/to/solana-php/src/';
       if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
           return;
       }
       $relative = substr($class, strlen($prefix));
       $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
       if (is_file($file)) {
           require $file;
       }
   });
   ```

3. Use the library normally.

**Caveat:** the `Psr18HttpClient` adapter requires the `psr/http-client` and `psr/http-factory` packages. Without them, that specific class can't be instantiated (you'll get a `TypeError` on its constructor). Stick to the built-in `CurlHttpClient` (which is the default for `RpcClient`) and you don't need PSR packages at all.

## What's Implemented

### Utilities (`SolanaPhpSdk\Util`)
- **`Base58`**: Bitcoin-alphabet Base58 with auto-selected GMP or BCMath backend.
- **`ByteBuffer`**: Sequential read/write buffer for binary serialization with full u64 range support (values beyond `PHP_INT_MAX` are handled as numeric strings).
- **`Ed25519`**: Native Ed25519 curve point validation. PHP's libsodium bindings do not expose `sodium_crypto_core_ed25519_is_valid_point`, so we implement RFC 8032 point decompression directly via GMP.
- **`CompactU16`**: Solana's 1-3 byte variable-length integer encoding (distinct from Borsh's fixed u32 prefix). Used in transaction wire format.

### Keys (`SolanaPhpSdk\Keypair`)
- **`PublicKey`**: Immutable 32-byte public key value object. Construct from Base58, raw bytes, integer arrays, or other `PublicKey` instances. Includes PDA derivation (`createProgramAddress`, `findProgramAddress`).
- **`Keypair`**: Ed25519 keypair via libsodium. Supports generation, seed-based derivation, and import from the Solana CLI JSON array format (`[174, 47, ...]`).

### Borsh (`SolanaPhpSdk\Borsh`)
- **Static facade** `Borsh` with fluent type constructors:
  ```php
  $schema = Borsh::struct([
      'instruction' => Borsh::u8(),
      'amount'      => Borsh::u64(),
      'memo'        => Borsh::option(Borsh::string()),
      'recipient'   => Borsh::publicKey(),
  ]);
  $bytes = Borsh::encode($schema, $value);
  $back  = Borsh::decode($schema, $bytes);
  ```
- **Primitive types:** u8, u16, u32, u64, u128, u256, i8, i16, i32, i64, f32, f64, bool, string, unit.
- **Composite types:** Option, Vec, fixed-size array, struct, enum (with unit and struct variants), HashMap.
- **Solana-specific:** `Borsh::publicKey()`: decodes directly to a `PublicKey` instance.
- **HashMap sorting:** matches the canonical `borsh-rs` behavior (sort by logical key value), NOT `borsh-js` (which doesn't sort). This is what on-chain Solana programs expect.

### Transactions (`SolanaPhpSdk\Transaction`)
- **`AccountMeta`**: (pubkey, isSigner, isWritable) with factory shortcuts: `signerWritable()`, `signerReadonly()`, `writable()`, `readonly()`.
- **`TransactionInstruction`**: (programId, accounts, data).
- **`Message`**: legacy-format message with the account dedup-and-order compilation algorithm. Handles the four-category ordering (writable signers, readonly signers, writable non-signers, readonly non-signers) and fee-payer-first invariant.
- **`Transaction`**: legacy signed transaction. Signing (full and partial), serialization, deserialization, and signature verification. Supports multi-sig flows.
- **`MessageV0`**: versioned (v0) message with full Address Lookup Table support. The compile algorithm matches `@solana/web3.js` byte-for-byte, including the four-category static-key ordering, the writable-then-readonly ALT drain, and combined-list account indexing for instructions.
- **`VersionedTransaction`**: v0 signed transaction envelope with `sign()`, `partialSign()`, `verifySignatures()`, `serialize()`, `deserialize()`, and a `peekVersion()` classifier for routing incoming wire bytes to the right class.
- **`AddressLookupTableAccount`**: value object for a (key, addresses) pair. Use with `MessageV0::compile()` to produce compact transactions.
- **`SignedTransaction`**: shared interface so `RpcClient::sendTransaction()` and `simulateTransaction()` accept either legacy or v0 transactions transparently.

### RPC client (`SolanaPhpSdk\Rpc`)
- **`RpcClient`**: speaks standard Solana JSON-RPC 2.0. Covers the core payment-workflow methods: `getBalance`, `getAccountInfo`, `getMinimumBalanceForRentExemption`, `getLatestBlockhash`, `sendTransaction`, `sendRawTransaction`, `simulateTransaction`, `getSignatureStatuses`, `getRecentPrioritizationFees`, plus a generic `call()` escape hatch for any other JSON-RPC method.
- **`Commitment`**: constants for PROCESSED / CONFIRMED / FINALIZED.
- **HTTP transport** is pluggable via the `HttpClient` interface:
  - `CurlHttpClient`: zero-dependency default using PHP's cURL extension.
  - `Psr18HttpClient`: adapter for any PSR-18 client (Guzzle, Symfony HttpClient, etc.).
  - Users can implement `HttpClient` themselves for mocks, custom auth, proxies, etc.

### Priority fee estimation (`SolanaPhpSdk\Rpc\Fee`)

Different RPC providers expose fee-market data in incompatible ways. Solana PHP abstracts this behind a single `FeeEstimator` interface with provider-specific implementations. Application code targets `FeeEstimator` and never branches on provider.

- **`PriorityLevel`**: five provider-agnostic buckets: MIN / LOW / MEDIUM / HIGH / VERY_HIGH.
- **`FeeEstimate`**: value object with all five bucket values (in micro-lamports per compute unit) plus the source estimator name.
- **`StandardFeeEstimator`**: works with any provider. Uses `getRecentPrioritizationFees` and computes percentiles client-side. Supports a floor value and customizable percentile-to-bucket mapping.
- **`HeliusFeeEstimator`**: uses Helius's native `getPriorityFeeEstimate` method. One RPC call returns all five buckets.
- **`TritonFeeEstimator`**: uses Triton One's percentile-extended `getRecentPrioritizationFees`. One call per bucket (5 total) but uses server-side percentile computation across the full slot window.

### Program instructions (`SolanaPhpSdk\Programs`)

Ready-to-use instruction builders for the Solana native and SPL programs that matter for payment flows. Every builder returns a `TransactionInstruction` ready to drop into `Transaction::new([...])`.

- **`ComputeBudgetProgram`**: `setComputeUnitLimit`, `setComputeUnitPrice`, `requestHeapFrame`, `setLoadedAccountsDataSizeLimit`. Critical for landing transactions under network contention.
- **`SystemProgram`**: `transfer` (SOL), `createAccount`, `assign`, `allocate`.
- **`TokenProgram`**: `transfer` and `transferChecked` for SPL tokens (USDC, USDT, PYUSD, etc.). Token-2022 supported via program ID override parameter.
- **`AssociatedTokenProgram`**: `findAssociatedTokenAddress` (pure off-chain PDA derivation), `create`, and `createIdempotent` (preferred for payment flows).
- **`MemoProgram`**: attach UTF-8 memos to transactions. Supports the V2 program with optional signer verification; standard for order-ID correlation in ecommerce flows.
- **`PaymentBuilder`**: high-level helper that bundles the compute-budget setup, ATA derivation, optional `createIdempotent`, `transferChecked`, Solana Pay references, memo attachment, blockhash fetch, and fee estimation into a fluent builder. Use this for the common ecommerce case; drop down to the primitives when you need byte-level control. See the example below.

### Solana Pay (`SolanaPhpSdk\SolanaPay`)

Construct and parse Solana Pay URLs - the standard URL format wallets use to compose payments from a QR code or deep link.

- **`TransferRequest`**: typed representation of a `solana:<recipient>?amount=...&spl-token=...&reference=...&label=...&message=...&memo=...` URL. All validation is at construction time.
- **`TransferRequestBuilder`**: fluent builder for clean call sites in merchant code.
- **`TransactionRequest`**: typed representation of a `solana:<httpsLink>` URL pointing to a merchant endpoint that returns a pre-built transaction.
- **`Url`**: static `encodeTransfer()`, `encodeTransaction()`, and `parse()` with auto-detection between the two URL shapes.
- **`PaymentFinder`**: find an on-chain transaction by its reference account (the merchant's order-correlation mechanism) via `getSignaturesForAddress`. Returns the signature and transaction payload for verification.

### Exceptions (`SolanaPhpSdk\Exception`)
- `SolanaException`: Root exception class.
- `InvalidArgumentException`: Input validation failures.
- `BorshException`: Borsh wire-format errors.
- `RpcException`: RPC / HTTP failures with optional HTTP status, JSON-RPC error code, and error data.
- `SolanaPayException`: Solana Pay URL / spec validation failures.

## Validation Against Reference Implementations

- **Ed25519 curve check / PDA derivation:** 50 golden curve-membership vectors and 3 full ATA derivations verified against `@solana/web3.js`.
- **Borsh encoding:** 23 golden wire-format vectors verified against `borsh-js`.
- **Borsh HashMap sorting:** verified against `borsh-rs` 1.5 (canonical Rust implementation, matches on-chain behavior).
- **Transaction wire format:** 3 golden transaction vectors verified against `@solana/web3.js`: byte-for-byte identical message, signature, and transaction bytes.
- **VersionedTransaction (v0) wire format:** 3 golden v0 vectors verified against `@solana/web3.js` covering no-ALT baseline, ALT with two readonly lookups, and ALT with mixed writable+readonly lookups. The compile algorithm (key collection, drain-into-ALT, four-category static ordering, combined-list instruction indexing) matches the reference byte-for-byte.
- **Program instructions:** 15+ golden instruction-data vectors verified against `@solana/web3.js` and `@solana/spl-token` covering ComputeBudget, System, Token, and Associated Token Account encodings.
- **Solana Pay URLs:** 10 golden URL vectors verified against `@solana/pay` covering amount formatting, special-character encoding (including UTF-8 labels), multi-reference ordering, and both conditional encodings of transaction requests.
- **RPC client:** tests use an in-memory `MockHttpClient` to exercise every method's request shape, response parsing, and error handling without a network.
- **End-to-end:** a full realistic payment transaction (ComputeBudget + createIdempotent + transferChecked + Memo) compiles, signs, serializes, round-trips, and verifies. A separate test demonstrates v0+ALT producing ~70% smaller transactions than the equivalent legacy form when touching 20 readonly accounts.

## Choosing an RPC provider and fee estimator

Solana PHP works with any Solana RPC provider - you only need to point `RpcClient` at a URL. Priority-fee estimation is a separate concern: different providers expose different fee-estimation APIs, so you pick an estimator class that matches your provider. These two choices are independent.

### Pointing at a provider

Pass the RPC endpoint URL to `RpcClient`:

```php
use SolanaPhpSdk\Rpc\RpcClient;

// Public mainnet (rate-limited, fine for development)
$rpc = new RpcClient('https://api.mainnet-beta.solana.com');

// Helius
$rpc = new RpcClient('https://mainnet.helius-rpc.com/?api-key=YOUR_KEY');

// Triton One
$rpc = new RpcClient('https://YOUR_NAMESPACE.rpcpool.com/YOUR_KEY');

// QuickNode, Alchemy, Ankr, Chainstack, etc. - all use their own URL format
$rpc = new RpcClient('https://your-endpoint.example.com/KEY');

// Local validator
$rpc = new RpcClient('http://127.0.0.1:8899');
```

Every provider implements the standard Solana JSON-RPC spec, so `RpcClient` just works everywhere. The URL is the only difference.

If your provider authenticates via a header rather than a query-string key, pass a pre-configured `CurlHttpClient`:

```php
use SolanaPhpSdk\Rpc\Http\CurlHttpClient;

$http = new CurlHttpClient(
    timeoutSeconds: 30,
    defaultHeaders: ['Authorization' => 'Bearer YOUR_TOKEN']
);
$rpc = new RpcClient('https://provider.example.com/rpc', $http);
```

### Picking a fee estimator

The SDK doesn't auto-detect which estimation method your provider supports - that would require a probe RPC call with uncertain failure modes. You pick explicitly:

```php
use SolanaPhpSdk\Rpc\Fee\{StandardFeeEstimator, HeliusFeeEstimator, TritonFeeEstimator};

// Works with ANY provider. Uses the standard getRecentPrioritizationFees
// method and computes percentiles client-side. Portable, slightly less accurate.
$fees = new StandardFeeEstimator($rpc);

// Helius's native getPriorityFeeEstimate method. One RPC call returns all five
// buckets with Helius's server-side estimator. Only works against Helius.
$fees = new HeliusFeeEstimator($rpc);

// Triton One's percentile-extended getRecentPrioritizationFees. Five RPC calls
// (one per bucket), server-side percentile math across the full slot window.
// Only works against Triton.
$fees = new TritonFeeEstimator($rpc);
```

All three implement the same `FeeEstimator` interface, so downstream code doesn't care which one it has:

```php
use SolanaPhpSdk\Rpc\Fee\PriorityLevel;

$microLamportsPerCU = $fees->estimateLevel($writableAccounts, PriorityLevel::MEDIUM);
```

### Quick guidance

| Use case                              | Provider                          | Estimator               |
|---------------------------------------|-----------------------------------|-------------------------|
| Prototyping / development             | Public `api.mainnet-beta.solana.com` | `StandardFeeEstimator` |
| Production ecom, modest volume        | Helius free/starter tier          | `HeliusFeeEstimator`    |
| High-volume or latency-sensitive      | Triton dedicated RPC              | `TritonFeeEstimator`    |
| Using QuickNode, Alchemy, Ankr, etc.  | Any provider URL                  | `StandardFeeEstimator`  |

The estimator isn't locked in at compile time - in a merchant-facing extension you typically read the provider choice from config and wire up the right estimator at boot:

```php
$rpc = new RpcClient($config['rpc_url']);

$fees = match ($config['fee_provider']) {
    'helius'   => new HeliusFeeEstimator($rpc),
    'triton'   => new TritonFeeEstimator($rpc),
    default    => new StandardFeeEstimator($rpc),
};
```

Adding support for a new provider-native estimator (QuickNode, Alchemy, Ankr) later is a ~100-line file implementing `FeeEstimator` plus one more `match` arm - no changes elsewhere.

## Example: building a USDC payment

The high-level path - use `PaymentBuilder` for the common case:

```php
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Programs\PaymentBuilder;
use SolanaPhpSdk\Rpc\Fee\HeliusFeeEstimator;
use SolanaPhpSdk\Rpc\Fee\PriorityLevel;
use SolanaPhpSdk\Rpc\RpcClient;

$rpc = new RpcClient('https://mainnet.helius-rpc.com/?api-key=...');
$fees = new HeliusFeeEstimator($rpc);

$customer = Keypair::fromSecretKey(...);
$merchant = new PublicKey('MERCHANT_WALLET...');
$usdc = new PublicKey('EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v');

$tx = PaymentBuilder::splToken($rpc, $usdc, 6)
    ->from($customer)
    ->to($merchant)
    ->amount(10_000_000)                          // 10 USDC (6 decimals)
    ->ensureRecipientAta()                        // auto-create merchant ATA if missing
    ->withFeeEstimate($fees, PriorityLevel::MEDIUM)
    ->memo('order_ref:OC-2025-00042')
    ->withFreshBlockhash()
    ->buildAndSign();

$signature = $rpc->sendTransaction($tx);
```

The low-level path - assemble the same transaction from primitives for full control:

```php
use SolanaPhpSdk\Programs\AssociatedTokenProgram;
use SolanaPhpSdk\Programs\ComputeBudgetProgram;
use SolanaPhpSdk\Programs\MemoProgram;
use SolanaPhpSdk\Programs\TokenProgram;
use SolanaPhpSdk\Transaction\Transaction;

[$customerAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress($customer->getPublicKey(), $usdc);
[$merchantAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress($merchant, $usdc);
$price = $fees->estimateLevel([$customerAta, $merchantAta], PriorityLevel::MEDIUM);

$tx = Transaction::new(
    [
        ComputeBudgetProgram::setComputeUnitLimit(80_000),
        ComputeBudgetProgram::setComputeUnitPrice($price),
        AssociatedTokenProgram::createIdempotent($customer->getPublicKey(), $merchantAta, $merchant, $usdc),
        TokenProgram::transferChecked($customerAta, $usdc, $merchantAta, $customer->getPublicKey(), 10_000_000, 6),
        MemoProgram::create('order_ref:OC-2025-00042'),
    ],
    $customer->getPublicKey(),
    $rpc->getLatestBlockhash()['blockhash']
);
$tx->sign($customer);
$signature = $rpc->sendTransaction($tx);
```

## Example: Solana Pay checkout

```php
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\RpcClient;
use SolanaPhpSdk\SolanaPay\PaymentFinder;
use SolanaPhpSdk\SolanaPay\TransferRequest;
use SolanaPhpSdk\SolanaPay\Url;

// 1. Merchant creates a unique reference per order (no private key needed).
$orderReference = Keypair::generate()->getPublicKey();

// 2. Build the payment URL - render this as a QR code.
$request = TransferRequest::builder(new PublicKey('MERCHANT_WALLET...'))
    ->amount('29.99')
    ->splToken(new PublicKey('EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v')) // USDC
    ->addReference($orderReference)
    ->label('Acme Store')
    ->message('Thanks for your order!')
    ->memo('order:OC-2025-00042')
    ->build();
$url = Url::encodeTransfer($request);   // solana:MERCHANT_WALLET...?amount=29.99&...

// 3. After the customer pays, verify with the reference pubkey server-side.
$rpc = new RpcClient('https://api.mainnet-beta.solana.com');
$finder = new PaymentFinder($rpc);
$signature = $finder->findByReference($orderReference);
if ($signature !== null) {
    // Transaction found - fetch it and validate amount/recipient match.
    $tx = $finder->getTransaction($signature);
    // Release the order...
}
```

## Framework Integrations

Solana PHP is deliberately framework-agnostic. It has zero runtime dependencies beyond PHP extensions and makes no assumptions about your application framework, routing, or persistence layer.

Framework-specific integrations (OpenCart, Magento, WooCommerce, Laravel, etc.) live in separate packages that depend on Solana PHP. This keeps the core library lean and lets each integration track its framework's release cadence independently.

## Testing

### Unit tests

```bash
composer install
composer test-unit
```

Current suite: 307 tests, 1029 assertions. Every wire format is validated byte-for-byte against `@solana/web3.js`, `@solana/spl-token`, `@solana/pay`, and `borsh-rs`.

### Live smoke test

`tests/Live/devnet_smoke.php` is a standalone script that exercises the library end-to-end against a real RPC endpoint. It builds real transactions, submits them, and waits for chain confirmation. Use this before shipping any integration to production.

```bash
# 1. Get a funded devnet keypair:
solana-keygen new --outfile ~/.config/solana/devnet-test.json
solana airdrop 2 --keypair ~/.config/solana/devnet-test.json \
    --url https://api.devnet.solana.com

# 2. (Optional) Grab some devnet USDC from https://faucet.circle.com
#    to exercise the SPL token path. Skipping this only skips two sub-tests.

# 3. Run the smoke test:
SOLANA_PHP_KEYPAIR_FILE=~/.config/solana/devnet-test.json \
  php tests/Live/devnet_smoke.php
```

The script covers: RPC connectivity, SOL transfer with confirmation, priority-fee estimation against the live chain, Solana Pay URL encoding with round-trip parsing, USDC ATA existence check, and USDC `transferChecked` with confirmation. Each step prints PASS/FAIL/SKIP with a clear reason, and the overall script exits non-zero if anything fails.

Environment variables:
- `SOLANA_PHP_KEYPAIR_FILE` - path to a `solana-keygen` JSON keypair (required)
- `SOLANA_PHP_KEYPAIR_JSON` - alternative: the JSON array as a string (useful in CI)
- `SOLANA_PHP_RPC_URL` - default: `https://api.devnet.solana.com`
- `SOLANA_PHP_MERCHANT` - default: ephemeral generated pubkey

## Roadmap

1. ✅ Utilities (Base58, ByteBuffer, Ed25519, CompactU16)
2. ✅ Keypair & PublicKey with PDA derivation
3. ✅ Borsh serialization layer
4. ✅ Transaction / Message / Instruction types (legacy format)
5. ✅ RPC client with provider-agnostic fee estimation (Standard / Helius / Triton)
6. ✅ Program instruction builders (ComputeBudget, System, Token, Associated Token, Memo)
7. ✅ Solana Pay URL encode/decode + payment verification helpers
8. ✅ VersionedTransaction (v0) with Address Lookup Tables

## License

MIT - see [LICENSE](LICENSE).
