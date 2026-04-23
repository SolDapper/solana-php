<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Fee;

use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\RpcClient;

/**
 * Fee estimator using Helius's native getPriorityFeeEstimate method.
 *
 * Helius operates a dedicated priority-fee service (their "Atlas" estimator)
 * that aggregates fee data across their infrastructure and exposes it via a
 * JSON-RPC method extension. The response already provides all five priority
 * buckets, so we just map their names onto ours and return.
 *
 * Requires the RpcClient to point at a Helius endpoint (mainnet.helius-rpc.com
 * or similar). Calling this against a non-Helius provider will fail with an
 * RPC "method not found" error.
 *
 * Wire format:
 *
 *   Request:
 *     method: getPriorityFeeEstimate
 *     params: [{
 *         accountKeys: [<base58 pubkey>, ...]          (preferred)
 *         // OR: transaction: <base58-encoded serialized tx>
 *         options: { includeAllPriorityFeeLevels: true }
 *     }]
 *
 *   Response:
 *     { priorityFeeLevels: { min, low, medium, high, veryHigh, unsafeMax } }
 *
 * Helius returns fee values as JSON floats (e.g. 10082.0). We cast to int,
 * which is correct since the underlying unit is micro-lamports and the
 * CU-price instruction only accepts integers anyway.
 */
final class HeliusFeeEstimator implements FeeEstimator
{
    private RpcClient $rpc;
    private bool $includeVote;

    /**
     * @param bool $includeVote Whether to include vote transactions in the
     *             sample. Default false: vote transactions are very low
     *             priority and including them drags estimates down.
     */
    public function __construct(RpcClient $rpc, bool $includeVote = false)
    {
        $this->rpc = $rpc;
        $this->includeVote = $includeVote;
    }

    public function estimate(array $writableAccounts = []): FeeEstimate
    {
        $accountKeys = [];
        foreach ($writableAccounts as $i => $pk) {
            if (!$pk instanceof PublicKey) {
                throw new \SolanaPhpSdk\Exception\InvalidArgumentException(
                    "writableAccounts[{$i}] must be a PublicKey instance"
                );
            }
            $accountKeys[] = $pk->toBase58();
        }

        $params = [[
            'accountKeys' => $accountKeys,
            'options' => [
                'includeAllPriorityFeeLevels' => true,
                'includeVote' => $this->includeVote,
            ],
        ]];

        $result = $this->rpc->call('getPriorityFeeEstimate', $params);

        if (!isset($result['priorityFeeLevels']) || !is_array($result['priorityFeeLevels'])) {
            throw new RpcException(
                "Helius response missing 'priorityFeeLevels'. Confirm the RPC endpoint is Helius."
            );
        }
        $lv = $result['priorityFeeLevels'];

        return new FeeEstimate(
            self::asInt($lv['min']       ?? 0),
            self::asInt($lv['low']       ?? 0),
            self::asInt($lv['medium']    ?? 0),
            self::asInt($lv['high']      ?? 0),
            self::asInt($lv['veryHigh']  ?? 0),
            'helius'
        );
    }

    public function estimateLevel(array $writableAccounts, string $level): int
    {
        return $this->estimate($writableAccounts)->get($level);
    }

    /**
     * Helius returns fees as JSON floats. Round-down-to-int after a safety
     * guard against NaN/inf.
     *
     * @param mixed $v
     */
    private static function asInt($v): int
    {
        if (is_int($v)) {
            return max(0, $v);
        }
        if (is_numeric($v)) {
            $f = (float) $v;
            if (is_nan($f) || is_infinite($f) || $f < 0) {
                return 0;
            }
            return (int) $f;
        }
        return 0;
    }
}
