<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Rpc;

use SolanaPhpSdk\Exception\RpcException;
use SolanaPhpSdk\Rpc\Http\HttpClient;

/**
 * Scripted HTTP client for unit tests.
 *
 * Queues up fake responses keyed by JSON-RPC method name, optionally with
 * additional matching on the first param for disambiguation. Also records
 * every request so assertions can verify exactly what the SDK sent.
 *
 * Usage:
 *   $mock = new MockHttpClient();
 *   $mock->on('getBalance')->respond(['value' => 1_000_000]);
 *   $mock->on('getLatestBlockhash')->respond(['value' => ['blockhash' => '...', 'lastValidBlockHeight' => 100]]);
 *   $rpc = new RpcClient('http://test', $mock);
 *   $balance = $rpc->getBalance($pk);
 *   $this->assertCount(1, $mock->requests);
 *   $this->assertSame('getBalance', $mock->requests[0]['method']);
 */
final class MockHttpClient implements HttpClient
{
    /**
     * Requests received, in order. Each entry:
     *   ['url' => string, 'method' => string, 'params' => array, 'body' => array, 'headers' => array]
     *
     * @var array<int, array{url: string, method: string, params: array, body: array, headers: array<string, string>}>
     */
    public array $requests = [];

    /**
     * Queued responses keyed by method name. Each method may have multiple
     * pending responses; they are consumed FIFO.
     *
     * @var array<string, array<int, array{result?: mixed, error?: array<string, mixed>, status: int}>>
     */
    private array $queued = [];

    /** Default response if no queued response matches. */
    private ?array $defaultResponse = null;

    /**
     * Start a fluent chain to configure a response for $method.
     */
    public function on(string $method): MockResponseBuilder
    {
        return new MockResponseBuilder($this, $method);
    }

    /**
     * Called internally by MockResponseBuilder.
     *
     * @param array{result?: mixed, error?: array<string, mixed>, status: int} $payload
     */
    public function enqueue(string $method, array $payload): void
    {
        $this->queued[$method][] = $payload;
    }

    public function setDefault(array $payload): void
    {
        $this->defaultResponse = $payload;
    }

    public function postJson(string $url, array $jsonBody, array $headers = []): array
    {
        $method = (string) ($jsonBody['method'] ?? '');
        $params = (array) ($jsonBody['params'] ?? []);

        $this->requests[] = [
            'url'     => $url,
            'method'  => $method,
            'params'  => $params,
            'body'    => $jsonBody,
            'headers' => $headers,
        ];

        if (isset($this->queued[$method]) && $this->queued[$method] !== []) {
            $payload = array_shift($this->queued[$method]);
        } elseif ($this->defaultResponse !== null) {
            $payload = $this->defaultResponse;
        } else {
            throw new RpcException("MockHttpClient: no response queued for method '{$method}'");
        }

        $status = $payload['status'] ?? 200;
        $response = [
            'jsonrpc' => '2.0',
            'id'      => $jsonBody['id'] ?? 1,
        ];
        if (array_key_exists('error', $payload)) {
            $response['error'] = $payload['error'];
        } else {
            $response['result'] = $payload['result'] ?? null;
        }

        return [$response, $status];
    }
}
