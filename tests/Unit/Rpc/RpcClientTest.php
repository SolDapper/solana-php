<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Rpc;

use PHPUnit\Framework\TestCase;
use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Rpc\Commitment;
use SolanaPhpSdk\Rpc\RpcClient;

final class RpcClientTest extends TestCase
{
    private const PK = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

    private function makeClient(MockHttpClient $mock): RpcClient
    {
        return new RpcClient('https://example.test/rpc', $mock);
    }

    public function testEmptyEndpointRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RpcClient('');
    }

    public function testInvalidCommitmentRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RpcClient('https://example.test', new MockHttpClient(), 'bogus');
    }

    public function testGetBalanceReturnsLamports(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getBalance')->respond([
            'context' => ['slot' => 100],
            'value'   => 1_500_000_000,
        ]);

        $balance = $this->makeClient($mock)->getBalance(new PublicKey(self::PK));

        $this->assertSame(1_500_000_000, $balance);
        $this->assertCount(1, $mock->requests);
        $req = $mock->requests[0];
        $this->assertSame('getBalance', $req['method']);
        $this->assertSame(self::PK, $req['params'][0]);
        $this->assertSame('confirmed', $req['params'][1]['commitment']);
    }

    public function testGetBalanceRespectsOverrideCommitment(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getBalance')->respond(['value' => 0]);
        $this->makeClient($mock)->getBalance(new PublicKey(self::PK), Commitment::FINALIZED);
        $this->assertSame('finalized', $mock->requests[0]['params'][1]['commitment']);
    }

    public function testU64AsStringPreserved(): void
    {
        // Some RPC fields return as strings to dodge JS precision loss.
        // Token amounts above 2^53 are the common case.
        $mock = new MockHttpClient();
        $big = '18446744073709551000'; // near-u64-max
        $mock->on('getBalance')->respond(['value' => $big]);

        $balance = $this->makeClient($mock)->getBalance(new PublicKey(self::PK));
        // String preserved since it exceeds PHP_INT_MAX.
        $this->assertSame($big, $balance);
    }

    public function testGetAccountInfoReturnsNullForMissingAccount(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getAccountInfo')->respond(['value' => null]);
        $this->assertNull($this->makeClient($mock)->getAccountInfo(new PublicKey(self::PK)));
    }

    public function testGetAccountInfoParsesBase64Data(): void
    {
        $mock = new MockHttpClient();
        $rawData = "\x01\x02\x03hello";
        $mock->on('getAccountInfo')->respond([
            'context' => ['slot' => 1],
            'value' => [
                'lamports'   => 2_039_280,
                'owner'      => self::PK,
                'data'       => [base64_encode($rawData), 'base64'],
                'executable' => false,
                'rentEpoch'  => 361,
            ],
        ]);

        $info = $this->makeClient($mock)->getAccountInfo(new PublicKey(self::PK));
        $this->assertNotNull($info);
        $this->assertSame(2_039_280, $info['lamports']);
        $this->assertSame($rawData, $info['data']);
        $this->assertInstanceOf(PublicKey::class, $info['owner']);
        $this->assertSame(self::PK, $info['owner']->toBase58());
        $this->assertFalse($info['executable']);
    }

    public function testGetLatestBlockhashReturnsBothFields(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getLatestBlockhash')->respond([
            'context' => ['slot' => 100],
            'value' => [
                'blockhash' => 'GHtXQBsoZHVnNFa9YevAzFr17DJjgHXk3ycTKD5xD3Zi',
                'lastValidBlockHeight' => 1234,
            ],
        ]);

        $bh = $this->makeClient($mock)->getLatestBlockhash();
        $this->assertSame('GHtXQBsoZHVnNFa9YevAzFr17DJjgHXk3ycTKD5xD3Zi', $bh['blockhash']);
        $this->assertSame(1234, $bh['lastValidBlockHeight']);
    }

    public function testSendRawTransactionBase64Encodes(): void
    {
        $mock = new MockHttpClient();
        $mock->on('sendTransaction')->respond('5YQ5fPqzLdR1fskpvKxPEZvTchaQBHhTpWQPiPdYTCFpVx');

        $rawWire = "\x01\x02\x03\x04";
        $signature = $this->makeClient($mock)->sendRawTransaction($rawWire);
        $this->assertSame('5YQ5fPqzLdR1fskpvKxPEZvTchaQBHhTpWQPiPdYTCFpVx', $signature);

        $req = $mock->requests[0];
        $this->assertSame(base64_encode($rawWire), $req['params'][0]);
        $this->assertSame('base64', $req['params'][1]['encoding']);
    }

    public function testJsonRpcErrorSurfacesAsRpcException(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getBalance')->respondError('Invalid params', -32602, ['hint' => 'bad pubkey']);

        try {
            $this->makeClient($mock)->getBalance(new PublicKey(self::PK));
            $this->fail('Expected RpcException');
        } catch (RpcException $e) {
            $this->assertStringContainsString('Invalid params', $e->getMessage());
            $this->assertSame(-32602, $e->getRpcErrorCode());
            $this->assertSame(['hint' => 'bad pubkey'], $e->getRpcErrorData());
        }
    }

    public function testHttpErrorStatusSurfaces(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getBalance')->respond(['value' => 0], 503);

        $this->expectException(RpcException::class);
        $this->expectExceptionMessage('HTTP 503');
        $this->makeClient($mock)->getBalance(new PublicKey(self::PK));
    }

    public function testGetSignatureStatusesHandlesMixedResult(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getSignatureStatuses')->respond([
            'context' => ['slot' => 100],
            'value' => [
                null, // unknown signature
                [
                    'slot' => 99,
                    'confirmations' => 10,
                    'confirmationStatus' => 'confirmed',
                    'err' => null,
                ],
            ],
        ]);

        $out = $this->makeClient($mock)->getSignatureStatuses(['sig1', 'sig2']);
        $this->assertCount(2, $out);
        $this->assertNull($out[0]);
        $this->assertSame(99, $out[1]['slot']);
        $this->assertSame('confirmed', $out[1]['confirmationStatus']);
    }

    public function testGetSignatureStatusesEmptyInputIsShortCircuited(): void
    {
        $mock = new MockHttpClient();
        $out = $this->makeClient($mock)->getSignatureStatuses([]);
        $this->assertSame([], $out);
        $this->assertCount(0, $mock->requests);
    }

    public function testGetRecentPrioritizationFeesReturnsArray(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getRecentPrioritizationFees')->respond([
            ['slot' => 348125, 'prioritizationFee' => 0],
            ['slot' => 348126, 'prioritizationFee' => 1000],
            ['slot' => 348127, 'prioritizationFee' => 500],
        ]);

        $fees = $this->makeClient($mock)->getRecentPrioritizationFees();
        $this->assertCount(3, $fees);
        $this->assertSame(1000, $fees[1]['prioritizationFee']);
        $this->assertSame(348126, $fees[1]['slot']);
    }

    public function testGetRecentPrioritizationFeesRejectsOver128Accounts(): void
    {
        $mock = new MockHttpClient();
        $keys = array_fill(0, 129, new PublicKey(self::PK));
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient($mock)->getRecentPrioritizationFees($keys);
    }

    public function testGetRecentPrioritizationFeesSendsBase58Keys(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getRecentPrioritizationFees')->respond([]);
        $this->makeClient($mock)->getRecentPrioritizationFees([new PublicKey(self::PK)]);
        $this->assertSame([[self::PK]], $mock->requests[0]['params']);
    }

    public function testGetMinimumBalanceForRentExemption(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getMinimumBalanceForRentExemption')->respond(2_039_280);
        $rent = $this->makeClient($mock)->getMinimumBalanceForRentExemption(165);
        $this->assertSame(2_039_280, $rent);
        $this->assertSame(165, $mock->requests[0]['params'][0]);
    }

    public function testCallIdIncrementsPerRequest(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getBalance')->respond(['value' => 0]);
        $mock->on('getBalance')->respond(['value' => 0]);

        $rpc = $this->makeClient($mock);
        $rpc->getBalance(new PublicKey(self::PK));
        $rpc->getBalance(new PublicKey(self::PK));

        $this->assertNotSame(
            $mock->requests[0]['body']['id'],
            $mock->requests[1]['body']['id'],
            'Each RPC call should use a unique request ID'
        );
    }

    public function testJsonRpcFieldsPresent(): void
    {
        $mock = new MockHttpClient();
        $mock->on('getBalance')->respond(['value' => 0]);
        $this->makeClient($mock)->getBalance(new PublicKey(self::PK));

        $body = $mock->requests[0]['body'];
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame('getBalance', $body['method']);
        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('params', $body);
    }
}
