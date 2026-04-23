<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Rpc;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\Fee\FeeEstimate;
use SolanaPhpSdk\Rpc\Fee\HeliusFeeEstimator;
use SolanaPhpSdk\Rpc\Fee\PriorityLevel;
use SolanaPhpSdk\Rpc\Fee\StandardFeeEstimator;
use SolanaPhpSdk\Rpc\Fee\TritonFeeEstimator;
use SolanaPhpSdk\Rpc\RpcClient;

final class FeeEstimatorTest extends TestCase
{
    private function makeClient(MockHttpClient $mock): RpcClient
    {
        return new RpcClient('https://example.test/rpc', $mock);
    }

    // ----- FeeEstimate value object --------------------------------------

    public function testFeeEstimateGetByLevel(): void
    {
        $est = new FeeEstimate(1, 2, 3, 4, 5);
        $this->assertSame(1, $est->get(PriorityLevel::MIN));
        $this->assertSame(2, $est->get(PriorityLevel::LOW));
        $this->assertSame(3, $est->get(PriorityLevel::MEDIUM));
        $this->assertSame(4, $est->get(PriorityLevel::HIGH));
        $this->assertSame(5, $est->get(PriorityLevel::VERY_HIGH));
    }

    public function testFeeEstimateRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeeEstimate(-1, 0, 0, 0, 0);
    }

    public function testFeeEstimateRejectsUnknownLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FeeEstimate(0, 0, 0, 0, 0))->get('bogus');
    }

    // ----- Percentile math -----------------------------------------------

    public function testPercentileEmptyReturnsZero(): void
    {
        $this->assertSame(0, StandardFeeEstimator::percentile([], 50.0));
    }

    public function testPercentileOnSortedKnownValues(): void
    {
        // NumPy-style linear interpolation reference:
        //   [1, 2, 3, 4] p=50 -> 2.5 -> 3 (round)
        //   [1, 2, 3, 4] p=25 -> 1.75 -> 2
        //   [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] p=90 -> 9.1 -> 9
        $this->assertSame(3, StandardFeeEstimator::percentile([1, 2, 3, 4], 50.0));
        $this->assertSame(2, StandardFeeEstimator::percentile([1, 2, 3, 4], 25.0));
        $this->assertSame(9, StandardFeeEstimator::percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 90.0));
    }

    public function testPercentileIgnoresInputOrder(): void
    {
        $this->assertSame(
            StandardFeeEstimator::percentile([1, 2, 3, 4, 5], 75.0),
            StandardFeeEstimator::percentile([5, 3, 1, 4, 2], 75.0)
        );
    }

    // ----- StandardFeeEstimator ------------------------------------------

    public function testStandardEstimatorComputesAllBuckets(): void
    {
        $mock = new MockHttpClient();
        // 10 samples with a clear distribution.
        $samples = [];
        foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000] as $fee) {
            $samples[] = ['slot' => 1, 'prioritizationFee' => $fee];
        }
        $mock->on('getRecentPrioritizationFees')->respond($samples);

        $rpc = $this->makeClient($mock);
        $estimator = new StandardFeeEstimator($rpc);
        $est = $estimator->estimate();

        // Default percentiles: MIN=25, LOW=50, MEDIUM=75, HIGH=90, VERY_HIGH=95
        $this->assertSame($est->min,      StandardFeeEstimator::percentile([100,200,300,400,500,600,700,800,900,1000], 25.0));
        $this->assertSame($est->low,      StandardFeeEstimator::percentile([100,200,300,400,500,600,700,800,900,1000], 50.0));
        $this->assertSame($est->medium,   StandardFeeEstimator::percentile([100,200,300,400,500,600,700,800,900,1000], 75.0));
        $this->assertSame($est->high,     StandardFeeEstimator::percentile([100,200,300,400,500,600,700,800,900,1000], 90.0));
        $this->assertSame($est->veryHigh, StandardFeeEstimator::percentile([100,200,300,400,500,600,700,800,900,1000], 95.0));

        // Sanity: monotonic across buckets.
        $this->assertLessThanOrEqual($est->low,      $est->min);
        $this->assertLessThanOrEqual($est->medium,   $est->low);
        $this->assertLessThanOrEqual($est->high,     $est->medium);
        $this->assertLessThanOrEqual($est->veryHigh, $est->high);
    }

    public function testStandardEstimatorAppliesMinFloor(): void
    {
        $mock = new MockHttpClient();
        // All samples zero — would normally give zero across all buckets.
        $mock->on('getRecentPrioritizationFees')->respond([
            ['slot' => 1, 'prioritizationFee' => 0],
            ['slot' => 2, 'prioritizationFee' => 0],
        ]);
        $rpc = $this->makeClient($mock);
        $estimator = new StandardFeeEstimator($rpc, null, /* minFloor */ 1000);
        $est = $estimator->estimate();

        $this->assertSame(1000, $est->min);
        $this->assertSame(1000, $est->veryHigh);
    }

    public function testStandardEstimatorEmptySamplesFallBackToFloor(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getRecentPrioritizationFees')->respond([]);
        $estimator = new StandardFeeEstimator($this->makeClient($mock), null, 500);
        $est = $estimator->estimate();
        $this->assertSame(500, $est->medium);
    }

    public function testStandardEstimatorRejectsBadPercentileMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StandardFeeEstimator(
            $this->makeClient(new MockHttpClient()),
            [PriorityLevel::MIN => 25.0] // missing other levels
        );
    }

    public function testStandardEstimatorRejectsOutOfRangePercentile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StandardFeeEstimator(
            $this->makeClient(new MockHttpClient()),
            [
                PriorityLevel::MIN => 25.0,
                PriorityLevel::LOW => 50.0,
                PriorityLevel::MEDIUM => 75.0,
                PriorityLevel::HIGH => 110.0, // invalid
                PriorityLevel::VERY_HIGH => 95.0,
            ]
        );
    }

    public function testEstimateLevelShortcut(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getRecentPrioritizationFees')->respond([
            ['slot' => 1, 'prioritizationFee' => 500],
            ['slot' => 2, 'prioritizationFee' => 1000],
            ['slot' => 3, 'prioritizationFee' => 1500],
        ]);
        $estimator = new StandardFeeEstimator($this->makeClient($mock));
        $medium = $estimator->estimateLevel([], PriorityLevel::MEDIUM);
        $this->assertIsInt($medium);
    }

    // ----- HeliusFeeEstimator --------------------------------------------

    public function testHeliusEstimatorMapsResponseShape(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getPriorityFeeEstimate')->respond([
            'priorityFeeLevels' => [
                'min'       => 0.0,
                'low'       => 2.0,
                'medium'    => 10082.0,
                'high'      => 100000.0,
                'veryHigh'  => 1000000.0,
                'unsafeMax' => 50000000.0,
            ],
        ]);

        $estimator = new HeliusFeeEstimator($this->makeClient($mock));
        $est = $estimator->estimate();

        $this->assertSame(0,       $est->min);
        $this->assertSame(2,       $est->low);
        $this->assertSame(10082,   $est->medium);
        $this->assertSame(100000,  $est->high);
        $this->assertSame(1000000, $est->veryHigh);
        $this->assertSame('helius', $est->source);
    }

    public function testHeliusEstimatorSendsExpectedParams(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getPriorityFeeEstimate')->respond([
            'priorityFeeLevels' => ['min' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'veryHigh' => 0],
        ]);

        $pk1 = new PublicKey('TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA');
        $pk2 = new PublicKey('ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL');

        $estimator = new HeliusFeeEstimator($this->makeClient($mock));
        $estimator->estimate([$pk1, $pk2]);

        $req = $mock->requests[0];
        $this->assertSame('getPriorityFeeEstimate', $req['method']);
        $params = $req['params'][0];
        $this->assertSame([$pk1->toBase58(), $pk2->toBase58()], $params['accountKeys']);
        $this->assertTrue($params['options']['includeAllPriorityFeeLevels']);
        $this->assertFalse($params['options']['includeVote']);
    }

    public function testHeliusEstimatorThrowsOnMissingShape(): void
    {
        $mock = new MockHttpClient();
        // Helius returns a bare priorityFeeEstimate instead of levels.
        $mock->on('getPriorityFeeEstimate')->respond(['priorityFeeEstimate' => 42]);

        $this->expectException(RpcException::class);
        $this->expectExceptionMessage("priorityFeeLevels");
        (new HeliusFeeEstimator($this->makeClient($mock)))->estimate();
    }

    public function testHeliusEstimatorHandlesFloatValues(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getPriorityFeeEstimate')->respond([
            'priorityFeeLevels' => [
                'min' => 0.5, 'low' => 1.9, 'medium' => 9999.99, 'high' => 1e6, 'veryHigh' => 1.5e7,
            ],
        ]);
        $est = (new HeliusFeeEstimator($this->makeClient($mock)))->estimate();
        $this->assertSame(0, $est->min);         // 0.5 truncated
        $this->assertSame(1, $est->low);
        $this->assertSame(9999, $est->medium);
        $this->assertSame(1000000, $est->high);
        $this->assertSame(15000000, $est->veryHigh);
    }

    public function testHeliusEstimatorClampsNegativeToZero(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getPriorityFeeEstimate')->respond([
            'priorityFeeLevels' => ['min' => -1, 'low' => 1, 'medium' => 2, 'high' => 3, 'veryHigh' => 4],
        ]);
        $est = (new HeliusFeeEstimator($this->makeClient($mock)))->estimate();
        $this->assertSame(0, $est->min);
    }

    // ----- TritonFeeEstimator --------------------------------------------

    public function testTritonEstimatorMakesOneRequestPerLevel(): void
    {
        $mock = new MockHttpClient();
        // 5 levels => 5 requests; queue a canned response for each.
        foreach ([100, 500, 1000, 5000, 10000] as $fee) {
            $mock->on('getRecentPrioritizationFees')->respond([
                ['slot' => 1, 'prioritizationFee' => $fee],
            ]);
        }

        $estimator = new TritonFeeEstimator($this->makeClient($mock));
        $est = $estimator->estimate();

        $this->assertCount(5, $mock->requests);
        $this->assertSame(100,   $est->min);
        $this->assertSame(500,   $est->low);
        $this->assertSame(1000,  $est->medium);
        $this->assertSame(5000,  $est->high);
        $this->assertSame(10000, $est->veryHigh);
        $this->assertSame('triton', $est->source);
    }

    public function testTritonEstimatorSendsBasisPointPercentile(): void
    {
        $mock = new MockHttpClient();
        for ($i = 0; $i < 5; $i++) {
            $mock->on('getRecentPrioritizationFees')->respond([['slot' => 1, 'prioritizationFee' => 1000]]);
        }

        (new TritonFeeEstimator($this->makeClient($mock)))->estimate();

        // Default bps: 2500, 5000, 7500, 9000, 9500 (in that order matching PriorityLevel::ALL)
        $expectedBps = [2500, 5000, 7500, 9000, 9500];
        foreach ($mock->requests as $i => $req) {
            $this->assertSame($expectedBps[$i], $req['params'][1]['percentile'], "Request {$i} bps");
        }
    }

    public function testTritonEstimatorTakesMaxAcrossSlots(): void
    {
        $mock = new MockHttpClient();
        // First request (MIN): 3 slots with fees 100, 200, 150 — max is 200.
        $mock->on('getRecentPrioritizationFees')->respond([
            ['slot' => 1, 'prioritizationFee' => 100],
            ['slot' => 2, 'prioritizationFee' => 200],
            ['slot' => 3, 'prioritizationFee' => 150],
        ]);
        // Remaining four requests: queue flat 0s.
        for ($i = 0; $i < 4; $i++) {
            $mock->on('getRecentPrioritizationFees')->respond([['slot' => 1, 'prioritizationFee' => 0]]);
        }

        $est = (new TritonFeeEstimator($this->makeClient($mock)))->estimate();
        $this->assertSame(200, $est->min);
    }

    public function testTritonEstimatorRejectsBadPercentileBps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TritonFeeEstimator(
            $this->makeClient(new MockHttpClient()),
            [
                PriorityLevel::MIN => 2500,
                PriorityLevel::LOW => 5000,
                PriorityLevel::MEDIUM => 7500,
                PriorityLevel::HIGH => 15000, // out of range (> 10000)
                PriorityLevel::VERY_HIGH => 9500,
            ]
        );
    }
}
