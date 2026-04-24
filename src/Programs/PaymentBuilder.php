<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Programs;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\Keypair;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\Commitment;
use SolanaPhpSdk\Rpc\ComputeUnitEstimator;
use SolanaPhpSdk\Rpc\ConfirmationOptions;
use SolanaPhpSdk\Rpc\ConfirmationResult;
use SolanaPhpSdk\Rpc\Fee\FeeEstimator;
use SolanaPhpSdk\Rpc\Fee\PriorityLevel;
use SolanaPhpSdk\Rpc\RpcClient;
use SolanaPhpSdk\Rpc\TransactionConfirmer;
use SolanaPhpSdk\Transaction\Transaction;
use SolanaPhpSdk\Transaction\TransactionInstruction;

/**
 * High-level helper for assembling a payment transaction.
 *
 * The low-level instruction builders ({@see SystemProgram}, {@see TokenProgram},
 * {@see AssociatedTokenProgram}, {@see ComputeBudgetProgram}, {@see MemoProgram})
 * are intentionally pure — they build one instruction each, with no RPC calls
 * or hidden behavior. That's the right default for power users who want full
 * control, but it means a straightforward "customer pays merchant X USDC"
 * flow requires ~5 builders wired together manually, plus the ATA derivation,
 * plus blockhash and fee lookups.
 *
 * PaymentBuilder bundles that wiring for the common ecommerce case:
 *
 *   $tx = PaymentBuilder::splToken($rpc, $usdcMint, $decimals = 6)
 *       ->from($customerKeypair)
 *       ->to($merchantWallet)
 *       ->amount(10_000_000)              // in base units (10 USDC)
 *       ->ensureRecipientAta()            // one RPC check + conditional create
 *       ->withFeeEstimate($heliusEstimator, PriorityLevel::MEDIUM)
 *       ->memo("order:{$orderId}")
 *       ->withFreshBlockhash()
 *       ->buildAndSign();
 *   $sig = $rpc->sendTransaction($tx);
 *
 * For SOL payments use the simpler static entry point:
 *
 *   $tx = PaymentBuilder::sol($rpc)
 *       ->from($customerKeypair)
 *       ->to($merchantWallet)
 *       ->amount(500_000_000)             // 0.5 SOL in lamports
 *       ->withFreshBlockhash()
 *       ->buildAndSign();
 *
 * Methods with RPC side effects are named explicitly (`ensureRecipientAta`,
 * `withFeeEstimate`, `withFreshBlockhash`) so you can tell from a call site
 * which steps make network calls. Everything else is pure computation.
 *
 * Defaults when methods are omitted:
 *   - Compute unit LIMIT: 200,000 (ample for most payment flows)
 *   - Compute unit PRICE: 1_000 micro-lamports/CU (safe floor — low enough
 *     to be virtually free, high enough to land outside of heavy congestion)
 *   - Blockhash: must be provided (either explicitly or via withFreshBlockhash)
 *   - ATA creation: NOT included unless ensureRecipientAta() is called OR
 *     createIdempotent() is called directly. `ensureRecipientAta` checks
 *     whether the recipient's ATA exists first; `createIdempotent` just
 *     includes a create ix unconditionally (idempotent so it's safe).
 *
 * The builder itself has no signing state — sign separately with
 * {@see self::buildAndSign()} or call {@see self::build()} to get an
 * unsigned Transaction you can pass around for multi-sig or offline signing.
 */
final class PaymentBuilder
{
    public const DEFAULT_CU_LIMIT = 200_000;
    public const DEFAULT_CU_PRICE = 1_000;

    private RpcClient $rpc;

    /** SOL-mode: null. SPL-mode: the mint pubkey. */
    private ?PublicKey $splTokenMint;

    /** SPL-mode: decimals of the mint (used for transferChecked). Ignored for SOL. */
    private ?int $splDecimals;

    private ?PublicKey $from = null;
    private ?Keypair $fromKeypair = null;
    private ?PublicKey $to = null;

    /** @var int|string|null Amount in base units (lamports for SOL, mint-decimals units for SPL). */
    private $amount = null;

    private ?string $memo = null;

    /** @var array<int, PublicKey> */
    private array $references = [];

    private ?string $blockhash = null;

    /**
     * The lastValidBlockHeight from the blockhash fetch, captured when
     * `withFreshBlockhash()` is used. Lets {@see self::buildSignAndSubmit()}
     * detect blockhash expiry during the confirmation wait. Null when the
     * blockhash was set externally via {@see self::blockhash()}, since we
     * don't know the validity window in that case.
     */
    private ?int $lastValidBlockHeight = null;

    private int $cuLimit = self::DEFAULT_CU_LIMIT;

    /** @var int|string */
    private $cuPrice = self::DEFAULT_CU_PRICE;

    /** True to include createIdempotent for the recipient ATA. */
    private bool $includeCreateIdempotent = false;

    /** Custom payer for ATA rent. Defaults to $from. */
    private ?PublicKey $ataRentPayer = null;

    /** Optional token program override (e.g. Token-2022). */
    private ?PublicKey $tokenProgramId = null;

    private function __construct(RpcClient $rpc, ?PublicKey $splTokenMint, ?int $splDecimals)
    {
        $this->rpc = $rpc;
        $this->splTokenMint = $splTokenMint;
        $this->splDecimals = $splDecimals;
    }

    /**
     * Entry point for SOL payments.
     */
    public static function sol(RpcClient $rpc): self
    {
        return new self($rpc, null, null);
    }

    /**
     * Entry point for SPL token payments.
     *
     * @param int $decimals The mint's decimals setting (required for transferChecked).
     *                      USDC = 6, USDT = 6, most stablecoins = 6.
     * @param PublicKey|null $tokenProgramId Optional Token-2022 program ID.
     */
    public static function splToken(
        RpcClient $rpc,
        PublicKey $mint,
        int $decimals,
        ?PublicKey $tokenProgramId = null
    ): self {
        if ($decimals < 0 || $decimals > 255) {
            throw new InvalidArgumentException("decimals must be 0..255, got {$decimals}");
        }
        $b = new self($rpc, $mint, $decimals);
        $b->tokenProgramId = $tokenProgramId;
        return $b;
    }

    // ----- Required fields -----------------------------------------------

    /**
     * Set the payer. Accepts either a Keypair (enables buildAndSign) or a
     * bare PublicKey (for build-only flows where signing happens elsewhere,
     * e.g. hardware wallets or server-side custody).
     *
     * @param Keypair|PublicKey $signer
     */
    public function from($signer): self
    {
        if ($signer instanceof Keypair) {
            $this->fromKeypair = $signer;
            $this->from = $signer->getPublicKey();
        } elseif ($signer instanceof PublicKey) {
            $this->from = $signer;
            $this->fromKeypair = null;
        } else {
            throw new InvalidArgumentException('from() requires a Keypair or PublicKey');
        }
        return $this;
    }

    public function to(PublicKey $recipient): self
    {
        $this->to = $recipient;
        return $this;
    }

    /**
     * Amount in base units.
     *   - SOL mode:  lamports (1 SOL = 1_000_000_000).
     *   - SPL mode:  mint-decimals units (1 USDC at 6 decimals = 1_000_000).
     *
     * @param int|string $amount Accepts numeric string for amounts beyond PHP_INT_MAX.
     */
    public function amount($amount): self
    {
        if (is_int($amount)) {
            if ($amount < 0) {
                throw new InvalidArgumentException('amount must be non-negative');
            }
        } elseif (is_string($amount)) {
            if (preg_match('/^\d+$/', $amount) !== 1) {
                throw new InvalidArgumentException('amount string must be a non-negative integer (no signs, no decimals)');
            }
        } else {
            throw new InvalidArgumentException('amount must be an integer or numeric string');
        }
        $this->amount = $amount;
        return $this;
    }

    // ----- Optional fields -----------------------------------------------

    /**
     * Attach an on-chain memo (UTF-8). Typically the merchant's order ID.
     */
    public function memo(string $text): self
    {
        $this->memo = $text;
        return $this;
    }

    /**
     * Add a reference account (Solana Pay convention). The reference appears
     * as a readonly non-signer account on the transfer instruction and becomes
     * searchable via getSignaturesForAddress for later payment verification.
     */
    public function addReference(PublicKey $reference): self
    {
        $this->references[] = $reference;
        return $this;
    }

    /**
     * Set the transaction's recent blockhash explicitly. Use this if you
     * already have a fresh blockhash from elsewhere in your app.
     */
    public function blockhash(string $blockhash): self
    {
        $this->blockhash = $blockhash;
        // Caller provided the blockhash externally; we don't know its validity window.
        $this->lastValidBlockHeight = null;
        return $this;
    }

    /**
     * Set the compute unit limit. Default is 200_000 which covers every
     * payment transaction comfortably.
     */
    public function computeUnitLimit(int $units): self
    {
        if ($units < 0) {
            throw new InvalidArgumentException('computeUnitLimit must be non-negative');
        }
        $this->cuLimit = $units;
        return $this;
    }

    /**
     * Set the compute unit price (micro-lamports per CU) explicitly.
     * Prefer {@see self::withFeeEstimate()} for market-aware pricing.
     *
     * @param int|string $microLamports
     */
    public function computeUnitPrice($microLamports): self
    {
        $this->cuPrice = $microLamports;
        return $this;
    }

    /**
     * Include an ATA-create instruction for the recipient unconditionally.
     * Safe because the instruction used is `createIdempotent` — if the ATA
     * already exists the instruction is a no-op (and costs no rent). Only
     * the ~20k CU budget and the potential rent (~0.002 SOL) are consumed
     * if creation actually happens.
     *
     * SOL mode: this method is a no-op (SOL transfers don't involve ATAs).
     */
    public function createIdempotent(): self
    {
        if ($this->splTokenMint === null) {
            // SOL mode — silently ignore for fluent-chain ergonomics.
            return $this;
        }
        $this->includeCreateIdempotent = true;
        return $this;
    }

    /**
     * Override who pays rent if the recipient's ATA needs to be created.
     * Defaults to the sender ($from). Useful when the merchant wants to
     * pre-fund ATAs for customers, or when rent is subsidized by a third
     * party.
     */
    public function ataRentPayer(PublicKey $payer): self
    {
        $this->ataRentPayer = $payer;
        return $this;
    }

    // ----- Methods that perform RPC calls --------------------------------

    /**
     * Fetch a fresh blockhash from the RPC. Replaces any previously-set
     * blockhash. Makes one RPC call.
     */
    public function withFreshBlockhash(?string $commitment = null): self
    {
        $latest = $this->rpc->getLatestBlockhash($commitment);
        $this->blockhash = $latest['blockhash'];
        $lastValid = $latest['lastValidBlockHeight'] ?? null;
        $this->lastValidBlockHeight = is_int($lastValid) ? $lastValid : null;
        return $this;
    }

    /**
     * Query an RPC-provider-specific fee estimator and set the compute unit
     * price based on current market conditions.
     *
     * The estimator sees the writable accounts for this transaction so it
     * can produce a contention-aware estimate (the merchant ATA in particular
     * is often hot for popular merchants).
     *
     * Makes 1-5 RPC calls depending on the estimator.
     *
     * @param string $level One of {@see PriorityLevel}. Default MEDIUM.
     */
    public function withFeeEstimate(FeeEstimator $estimator, string $level = PriorityLevel::MEDIUM): self
    {
        $writableAccounts = $this->computeWritableAccounts();
        $this->cuPrice = $estimator->estimateLevel($writableAccounts, $level);
        return $this;
    }

    /**
     * Measure actual compute-unit consumption via `simulateTransaction` and
     * set the CU limit to the observed value times a safety multiplier.
     *
     * Makes one RPC call (the simulation). Must be called AFTER from(), to(),
     * and amount() are set - otherwise there's no business logic to simulate.
     * Can be called in any order relative to the blockhash-setting methods;
     * simulation uses `replaceRecentBlockhash: true` internally and doesn't
     * need a real blockhash.
     *
     * Why this matters: a naive 200,000 CU default works for most payments but
     * overpays priority fees by ~400x on simple transfers (which actually use
     * ~450 CU). On high volume this is real money. A too-low default fails on
     * chain when hitting heavier flows. Simulation plus a 10% margin gets
     * both right with no guesswork.
     *
     * For transactions touching volatile state (price oracles, AMM pools)
     * where slot-to-slot CU variance is higher, bump the multiplier to 1.2
     * or 1.3. 1.1 is fine for deterministic flows like payment transfers.
     *
     * @param float $multiplier Safety margin applied to the simulated value.
     *        Default 1.1 (10% headroom). Must be >= 1.0.
     * @param int $floor Minimum CU limit regardless of simulation result.
     *        Default 1000. Guards against implausibly low simulation values.
     *
     * @throws InvalidArgumentException If from/to/amount are not yet set,
     *         or if $multiplier/$floor are invalid.
     * @throws \SolanaPhpSdk\Exception\RpcException On RPC-level failures.
     */
    public function withSimulatedComputeUnitLimit(float $multiplier = 1.1, int $floor = 1000): self
    {
        if ($this->from === null || $this->to === null || $this->amount === null) {
            throw new InvalidArgumentException(
                'withSimulatedComputeUnitLimit() requires from(), to(), and amount() to be set first'
            );
        }

        $businessIxs = $this->assembleBusinessInstructions();

        // Any 32 raw bytes work here because simulateTransaction uses
        // replaceRecentBlockhash: true. We don't want to force a network
        // call to fetch a real blockhash just for simulation.
        $placeholderBlockhash = str_repeat("\x00", 32);

        $estimate = (new ComputeUnitEstimator($this->rpc))->estimateLegacy(
            $businessIxs,
            $this->from,
            $placeholderBlockhash,
            $multiplier,
            $floor
        );

        $this->cuLimit = $estimate->recommendedLimit;
        return $this;
    }

    /**
     * Check whether the recipient's ATA exists. If it does not, automatically
     * include a createIdempotent instruction in the transaction. If it does
     * exist, skip the create (saving ~20k CUs).
     *
     * Makes one RPC call (getAccountInfo).
     *
     * Only meaningful in SPL mode. SOL mode is a no-op.
     *
     * Use {@see self::createIdempotent()} instead if you want to
     * unconditionally include the create instruction without an RPC round-trip;
     * that's slightly less efficient but avoids the network dependency.
     */
    public function ensureRecipientAta(?string $commitment = null): self
    {
        if ($this->splTokenMint === null || $this->to === null) {
            return $this;
        }
        [$recipientAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
            $this->to, $this->splTokenMint, $this->tokenProgramId
        );
        $info = $this->rpc->getAccountInfo($recipientAta, $commitment ?? Commitment::CONFIRMED);
        $this->includeCreateIdempotent = ($info === null);
        return $this;
    }

    // ----- Output ---------------------------------------------------------

    /**
     * Assemble an unsigned Transaction. Useful when signing happens elsewhere
     * (hardware wallet, out-of-band co-signer, server-side custody).
     */
    public function build(): Transaction
    {
        $this->validate();

        $instructions = $this->assembleInstructions();

        return Transaction::new($instructions, $this->from, $this->blockhash);
    }

    /**
     * Assemble and sign with the Keypair passed to {@see self::from()}.
     * Throws if from() was called with a bare PublicKey rather than a Keypair.
     *
     * Additional co-signers can be provided as arguments for multi-sig flows.
     */
    public function buildAndSign(Keypair ...$additionalSigners): Transaction
    {
        if ($this->fromKeypair === null) {
            throw new InvalidArgumentException(
                'buildAndSign() requires a Keypair. Call from() with a Keypair, or use build() to get an unsigned transaction.'
            );
        }
        $tx = $this->build();
        $tx->sign($this->fromKeypair, ...$additionalSigners);
        return $tx;
    }

    /**
     * Build, sign, submit, and wait for the transaction to confirm in
     * one fluent call. The most ergonomic option for the common ecom
     * checkout flow.
     *
     * Re-broadcast is enabled by default during the wait - validators
     * sometimes silently drop transactions, and re-submitting the same
     * signed wire bytes every few seconds substantially improves landing
     * rates on a busy mainnet. To disable, pass an options object with
     * a null rebroadcastWireBytes (or just use the lower-level
     * {@see self::buildAndSign()} + manual submit + manual confirm flow).
     *
     * @param ConfirmationOptions|null $options Confirmation strategy. Default
     *        is `ConfirmationOptions::confirmed()` with rebroadcast enabled.
     * @param Keypair[] $additionalSigners Co-signers for multi-sig payment flows.
     *
     * @throws InvalidArgumentException If from() was called with a bare PublicKey.
     * @throws \SolanaPhpSdk\Exception\RpcException If submission itself fails (the
     *         confirmation wait swallows transient RPC errors and retries).
     */
    public function buildSignAndSubmit(
        ?ConfirmationOptions $options = null,
        Keypair ...$additionalSigners
    ): ConfirmationResult {
        $tx = $this->buildAndSign(...$additionalSigners);
        $wire = $tx->serialize();

        // Default to confirmed-with-rebroadcast for the common ecom case.
        // Caller can override with finalized or with rebroadcast disabled.
        $options ??= ConfirmationOptions::confirmed()->withRebroadcast($wire);
        // If caller passed options without setting rebroadcast bytes, fill them in -
        // we have the wire and they almost certainly want it re-broadcast.
        if ($options->rebroadcastWireBytes === null) {
            $options = $options->withRebroadcast($wire, $options->rebroadcastEvery);
        }
        // If caller didn't set blockhash expiry tracking but we have the height
        // from withFreshBlockhash, layer it on - automatic expiry detection.
        if ($options->lastValidBlockHeight === null && $this->lastValidBlockHeight !== null) {
            $options = $options->withBlockhashExpiry($this->lastValidBlockHeight);
        }

        $signature = $this->rpc->sendTransaction($tx);
        $confirmer = new TransactionConfirmer($this->rpc);
        return $confirmer->awaitConfirmation($signature, $options);
    }

    // ----- Internals ------------------------------------------------------

    /**
     * @return array<int, TransactionInstruction>
     */
    private function assembleInstructions(): array
    {
        // Compute budget instructions come first (best practice — they apply
        // to the whole transaction regardless of position, but convention is
        // to emit them early for easy visual inspection).
        return array_merge(
            [
                ComputeBudgetProgram::setComputeUnitLimit($this->cuLimit),
                ComputeBudgetProgram::setComputeUnitPrice($this->cuPrice),
            ],
            $this->assembleBusinessInstructions()
        );
    }

    /**
     * Assemble only the business-logic instructions, excluding the
     * ComputeBudgetProgram prefix. Used by simulation code paths that need
     * to measure CU consumption without double-counting the compute-budget
     * instructions themselves (which are cheap but not zero).
     *
     * @return array<int, TransactionInstruction>
     */
    private function assembleBusinessInstructions(): array
    {
        $ixs = [];

        if ($this->splTokenMint !== null) {
            // SPL token flow
            [$fromAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
                $this->from, $this->splTokenMint, $this->tokenProgramId
            );
            [$toAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
                $this->to, $this->splTokenMint, $this->tokenProgramId
            );

            if ($this->includeCreateIdempotent) {
                $rentPayer = $this->ataRentPayer ?? $this->from;
                $ixs[] = AssociatedTokenProgram::createIdempotent(
                    $rentPayer,
                    $toAta,
                    $this->to,
                    $this->splTokenMint,
                    $this->tokenProgramId
                );
            }

            $transferIx = TokenProgram::transferChecked(
                $fromAta,
                $this->splTokenMint,
                $toAta,
                $this->from,
                $this->amount,
                $this->splDecimals,
                $this->tokenProgramId
            );
            $transferIx = $this->attachReferencesIfAny($transferIx);
            $ixs[] = $transferIx;
        } else {
            // SOL flow
            $transferIx = SystemProgram::transfer($this->from, $this->to, $this->amount);
            $transferIx = $this->attachReferencesIfAny($transferIx);
            $ixs[] = $transferIx;
        }

        if ($this->memo !== null) {
            $ixs[] = MemoProgram::create($this->memo);
        }

        return $ixs;
    }

    /**
     * Attach reference accounts to a transfer instruction as readonly
     * non-signer accounts — the Solana Pay convention for linking an
     * on-chain transaction to an off-chain order record.
     *
     * Returns a new TransactionInstruction; does not mutate the input.
     */
    private function attachReferencesIfAny(TransactionInstruction $ix): TransactionInstruction
    {
        if ($this->references === []) {
            return $ix;
        }
        $accounts = $ix->accounts;
        foreach ($this->references as $ref) {
            $accounts[] = \SolanaPhpSdk\Transaction\AccountMeta::readonly($ref);
        }
        return new TransactionInstruction($ix->programId, $accounts, $ix->data);
    }

    /**
     * Compute the set of writable accounts (excluding signers) for fee-
     * estimation purposes. Fee markets are per-account, so the estimator
     * wants to know which accounts the transaction will lock for write access.
     *
     * @return array<int, PublicKey>
     */
    private function computeWritableAccounts(): array
    {
        $writable = [];
        if ($this->splTokenMint !== null && $this->to !== null && $this->from !== null) {
            [$fromAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
                $this->from, $this->splTokenMint, $this->tokenProgramId
            );
            [$toAta, ] = AssociatedTokenProgram::findAssociatedTokenAddress(
                $this->to, $this->splTokenMint, $this->tokenProgramId
            );
            $writable[] = $fromAta;
            $writable[] = $toAta;
        } elseif ($this->to !== null) {
            // SOL transfer: destination is writable.
            $writable[] = $this->to;
        }
        return $writable;
    }

    private function validate(): void
    {
        if ($this->from === null) {
            throw new InvalidArgumentException('from() is required');
        }
        if ($this->to === null) {
            throw new InvalidArgumentException('to() is required');
        }
        if ($this->amount === null) {
            throw new InvalidArgumentException('amount() is required');
        }
        if ($this->blockhash === null) {
            throw new InvalidArgumentException(
                'blockhash is required. Call withFreshBlockhash() or blockhash($hash).'
            );
        }
    }
}
