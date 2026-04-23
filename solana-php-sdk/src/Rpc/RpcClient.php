<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\Http\CurlHttpClient;
use SolanaPhpSdk\Rpc\Http\HttpClient;
use SolanaPhpSdk\Transaction\SignedTransaction;
use SolanaPhpSdk\Transaction\Transaction;

/**
 * JSON-RPC 2.0 client for the standard Solana RPC API.
 *
 * Speaks only methods defined in the core Solana RPC spec — no
 * provider-specific extensions. Helius, Triton, QuickNode, etc. are
 * handled by separate {@see \SolanaPhpSdk\Rpc\Fee\FeeEstimator}
 * implementations that layer on top of this client.
 *
 * Usage:
 *
 *   $rpc = new RpcClient('https://api.mainnet-beta.solana.com');
 *   $balance = $rpc->getBalance($publicKey);
 *   $blockhash = $rpc->getLatestBlockhash();
 *   $signature = $rpc->sendTransaction($signedTx);
 *
 * All methods throw {@see RpcException} on HTTP/transport failures or when
 * the RPC endpoint returns a JSON-RPC error. Returned values are decoded
 * into native PHP types:
 *
 *   - Lamport amounts and token amounts: int when fits PHP_INT_MAX, else
 *     numeric string (like the Borsh u64 handling elsewhere in the SDK).
 *   - Slots, blockheights: int (64-bit PHP assumed).
 *   - Pubkeys returned by the RPC: PublicKey instances.
 *   - Binary account data: raw bytes (base64-decoded from the RPC's
 *     "base64" encoding).
 */
final class RpcClient
{
    private string $endpoint;
    private HttpClient $http;
    private string $defaultCommitment;
    private int $requestIdCounter = 0;

    /**
     * @param string $endpoint Full RPC URL including any API key query string.
     * @param HttpClient|null $http HTTP transport. Defaults to a plain cURL client.
     * @param string $defaultCommitment Commitment applied when methods don't receive one explicitly.
     */
    public function __construct(
        string $endpoint,
        ?HttpClient $http = null,
        string $defaultCommitment = Commitment::CONFIRMED
    ) {
        if ($endpoint === '') {
            throw new InvalidArgumentException('RPC endpoint URL cannot be empty');
        }
        if (!Commitment::isValid($defaultCommitment)) {
            throw new InvalidArgumentException("Invalid commitment: {$defaultCommitment}");
        }
        $this->endpoint = $endpoint;
        $this->http = $http ?? new CurlHttpClient();
        $this->defaultCommitment = $defaultCommitment;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getHttpClient(): HttpClient
    {
        return $this->http;
    }

    /**
     * Execute an arbitrary JSON-RPC method. Public so that extension classes
     * (provider-specific fee estimators, etc.) can reuse the transport and
     * error-handling logic.
     *
     * @param array<int, mixed> $params
     * @return mixed Decoded value of the "result" field.
     * @throws RpcException
     */
    public function call(string $method, array $params = [])
    {
        $requestId = ++$this->requestIdCounter;
        $payload = [
            'jsonrpc' => '2.0',
            'id'      => $requestId,
            'method'  => $method,
            'params'  => $params,
        ];

        [$response, $status] = $this->http->postJson($this->endpoint, $payload);

        if ($status < 200 || $status >= 300) {
            // Some providers return JSON-RPC errors with non-200 statuses;
            // surface both pieces of information if present.
            $errMsg = isset($response['error']['message']) ? (string) $response['error']['message'] : 'HTTP error';
            throw new RpcException(
                "RPC request failed (HTTP {$status}): {$errMsg}",
                $status,
                $response['error']['code'] ?? null,
                $response['error']['data'] ?? null
            );
        }

        if (isset($response['error'])) {
            $err = $response['error'];
            $msg = is_array($err) && isset($err['message']) ? (string) $err['message'] : json_encode($err);
            $code = is_array($err) && isset($err['code']) ? (int) $err['code'] : null;
            $data = is_array($err) ? ($err['data'] ?? null) : null;
            throw new RpcException(
                "JSON-RPC error for method '{$method}': {$msg}",
                $status,
                $code,
                $data
            );
        }

        if (!array_key_exists('result', $response)) {
            throw new RpcException("RPC response missing 'result' field for method '{$method}'");
        }

        return $response['result'];
    }

    // ============ Core account + balance methods =========================

    /**
     * Get the balance of a public key in lamports.
     *
     * @return int|string Lamport amount.
     */
    public function getBalance(PublicKey $publicKey, ?string $commitment = null)
    {
        $result = $this->call('getBalance', [
            $publicKey->toBase58(),
            ['commitment' => $commitment ?? $this->defaultCommitment],
        ]);
        // getBalance returns {context, value} where value is the lamport count.
        return $this->normalizeU64($result['value'] ?? 0);
    }

    /**
     * Fetch an account's data, owner, and lamport balance.
     *
     * @return array{
     *     lamports: int|string,
     *     owner: PublicKey,
     *     data: string,
     *     executable: bool,
     *     rentEpoch: int|string
     * }|null Null if the account does not exist.
     */
    public function getAccountInfo(PublicKey $publicKey, ?string $commitment = null): ?array
    {
        $result = $this->call('getAccountInfo', [
            $publicKey->toBase58(),
            [
                'commitment' => $commitment ?? $this->defaultCommitment,
                'encoding'   => 'base64',
            ],
        ]);

        $value = $result['value'] ?? null;
        if ($value === null) {
            return null;
        }

        // `data` arrives as ["<base64>", "base64"]
        $dataField = $value['data'] ?? null;
        if (is_array($dataField) && isset($dataField[0])) {
            $raw = base64_decode((string) $dataField[0], true);
            if ($raw === false) {
                throw new RpcException('Failed to decode base64 account data');
            }
        } else {
            $raw = '';
        }

        return [
            'lamports'   => $this->normalizeU64($value['lamports'] ?? 0),
            'owner'      => new PublicKey((string) $value['owner']),
            'data'       => $raw,
            'executable' => (bool) ($value['executable'] ?? false),
            'rentEpoch'  => $this->normalizeU64($value['rentEpoch'] ?? 0),
        ];
    }

    /**
     * Minimum lamports required for rent exemption of an account of the given size.
     *
     * @return int|string
     */
    public function getMinimumBalanceForRentExemption(int $dataLength, ?string $commitment = null)
    {
        if ($dataLength < 0) {
            throw new InvalidArgumentException('dataLength must be non-negative');
        }
        $result = $this->call('getMinimumBalanceForRentExemption', [
            $dataLength,
            ['commitment' => $commitment ?? $this->defaultCommitment],
        ]);
        return $this->normalizeU64($result);
    }

    // ============ Blockhash + transaction submission ======================

    /**
     * Fetch the most recent blockhash along with the last slot at which it
     * remains valid for transaction submission.
     *
     * @return array{blockhash: string, lastValidBlockHeight: int|string}
     */
    public function getLatestBlockhash(?string $commitment = null): array
    {
        $result = $this->call('getLatestBlockhash', [
            ['commitment' => $commitment ?? $this->defaultCommitment],
        ]);
        $value = $result['value'] ?? $result;
        return [
            'blockhash'            => (string) $value['blockhash'],
            'lastValidBlockHeight' => $this->normalizeU64($value['lastValidBlockHeight'] ?? 0),
        ];
    }

    /**
     * Submit a signed Transaction to the network.
     *
     * @param SignedTransaction $transaction Either a legacy {@see Transaction}
     *        or a {@see VersionedTransaction}. Must be fully signed.
     * @param array<string, mixed> $options Extra options passed through to the
     *        RPC (e.g. 'skipPreflight', 'maxRetries', 'preflightCommitment').
     * @return string Base58-encoded signature (the transaction ID).
     */
    public function sendTransaction(SignedTransaction $transaction, array $options = []): string
    {
        $wire = $transaction->serialize();
        return $this->sendRawTransaction($wire, $options);
    }

    /**
     * Submit pre-serialized transaction bytes. Useful when the transaction
     * was produced or co-signed elsewhere and you already have the wire
     * form in hand.
     *
     * @param string $wireBytes Raw serialized transaction bytes.
     * @param array<string, mixed> $options
     * @return string Base58-encoded transaction signature.
     */
    public function sendRawTransaction(string $wireBytes, array $options = []): string
    {
        $encoded = base64_encode($wireBytes);
        // Default to base64 encoding, but let callers override.
        $opts = array_merge(['encoding' => 'base64'], $options);

        $result = $this->call('sendTransaction', [$encoded, $opts]);
        return (string) $result;
    }

    /**
     * Simulate a transaction's execution without submitting it.
     *
     * Returns the raw RPC result, which includes `err` (null on success),
     * `logs` (program log output), `unitsConsumed` (the compute units
     * actually used), and account data for any accounts requested via
     * options. Callers can feed `unitsConsumed` back into a
     * ComputeBudgetProgram setComputeUnitLimit instruction.
     *
     * Accepts either a legacy {@see Transaction} or a {@see VersionedTransaction}.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function simulateTransaction(SignedTransaction $transaction, array $options = []): array
    {
        $wire = $transaction->serialize(/* verifySignatures */ false);
        $encoded = base64_encode($wire);
        $opts = array_merge([
            'encoding'   => 'base64',
            'commitment' => $this->defaultCommitment,
            'sigVerify'  => false,
            'replaceRecentBlockhash' => true,
        ], $options);

        $result = $this->call('simulateTransaction', [$encoded, $opts]);
        return $result['value'] ?? $result;
    }

    /**
     * Check the confirmation status of one or more transaction signatures.
     *
     * @param array<int, string> $signatures Base58-encoded transaction signatures.
     * @return array<int, ?array{slot: int|string, confirmations: int|string|null, confirmationStatus: string|null, err: mixed}>
     */
    public function getSignatureStatuses(array $signatures, bool $searchTransactionHistory = false): array
    {
        if ($signatures === []) {
            return [];
        }
        foreach ($signatures as $i => $sig) {
            if (!is_string($sig)) {
                throw new InvalidArgumentException("Signature at index {$i} must be a Base58 string");
            }
        }
        $result = $this->call('getSignatureStatuses', [
            $signatures,
            ['searchTransactionHistory' => $searchTransactionHistory],
        ]);
        $out = [];
        foreach ($result['value'] ?? [] as $entry) {
            if ($entry === null) {
                $out[] = null;
                continue;
            }
            $out[] = [
                'slot'               => $this->normalizeU64($entry['slot'] ?? 0),
                'confirmations'      => isset($entry['confirmations']) ? $this->normalizeU64($entry['confirmations']) : null,
                'confirmationStatus' => $entry['confirmationStatus'] ?? null,
                'err'                => $entry['err'] ?? null,
            ];
        }
        return $out;
    }

    // ============ Fee-market inputs (provider-agnostic) ===================

    /**
     * Fetch the standard prioritization fee samples for recent slots.
     *
     * The raw RPC response is an array of {slot, prioritizationFee} entries
     * where `prioritizationFee` is in micro-lamports per compute unit.
     *
     * This is the vanilla method that works across every provider. Use a
     * {@see \SolanaPhpSdk\Rpc\Fee\FeeEstimator} for a higher-level estimate.
     *
     * @param array<int, PublicKey> $writableAccounts Optional; limits samples to
     *        transactions that locked these accounts as writable. Maximum 128.
     * @return array<int, array{slot: int|string, prioritizationFee: int|string}>
     */
    public function getRecentPrioritizationFees(array $writableAccounts = []): array
    {
        if (count($writableAccounts) > 128) {
            throw new InvalidArgumentException('getRecentPrioritizationFees accepts at most 128 accounts');
        }
        $params = [];
        if ($writableAccounts !== []) {
            $addresses = [];
            foreach ($writableAccounts as $pk) {
                if (!$pk instanceof PublicKey) {
                    throw new InvalidArgumentException('writableAccounts must contain PublicKey instances');
                }
                $addresses[] = $pk->toBase58();
            }
            $params[] = $addresses;
        }
        $result = $this->call('getRecentPrioritizationFees', $params);
        $out = [];
        foreach ($result as $entry) {
            $out[] = [
                'slot'              => $this->normalizeU64($entry['slot'] ?? 0),
                'prioritizationFee' => $this->normalizeU64($entry['prioritizationFee'] ?? 0),
            ];
        }
        return $out;
    }

    // ============ Helpers =================================================

    /**
     * Decode a u64-shaped value. RPC endpoints usually return these as plain
     * JSON numbers but some (notably token amounts) return them as strings
     * to avoid JS precision loss. We accept both.
     *
     * @param mixed $value
     * @return int|string
     */
    private function normalizeU64($value)
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            // If it fits in PHP_INT_MAX, upgrade to int for ergonomic use.
            if (PHP_INT_SIZE === 8 && bccomp($value, (string) PHP_INT_MAX) <= 0) {
                return (int) $value;
            }
            return $value;
        }
        if (is_float($value)) {
            // Coerce floats defensively (RPC sometimes returns large ints as floats in JSON).
            return (int) $value;
        }
        return 0;
    }
}
