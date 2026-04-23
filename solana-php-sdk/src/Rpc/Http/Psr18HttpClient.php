<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SolanaPhpSdk\Exception\RpcException;

/**
 * PSR-18 HTTP client adapter.
 *
 * Accepts any PSR-18 ClientInterface plus the PSR-17 factories for Request
 * and Stream. Use this to share an existing HTTP stack (Guzzle, Symfony,
 * buzz, etc.) including its middleware, logging, and connection pooling.
 *
 * Example with Guzzle:
 *
 *   $httpClient = new Psr18HttpClient(
 *       new GuzzleHttp\Client(['timeout' => 30]),
 *       new GuzzleHttp\Psr7\HttpFactory(),
 *       new GuzzleHttp\Psr7\HttpFactory()
 *   );
 *
 * Note: This class does NOT require Guzzle or any specific PSR-18
 * implementation as a hard dependency — it only requires the PSR interfaces.
 * Users must install a PSR-18 client package themselves if they want to use
 * this adapter. If psr/http-client isn't installed at all, don't reference
 * this class; use {@see CurlHttpClient} instead.
 */
final class Psr18HttpClient implements HttpClient
{
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var array<string, string> */
    private array $defaultHeaders;

    /**
     * @param array<string, string> $defaultHeaders Applied to every request.
     */
    public function __construct(
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        array $defaultHeaders = []
    ) {
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->defaultHeaders = $defaultHeaders;
    }

    public function postJson(string $url, array $jsonBody, array $headers = []): array
    {
        $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RpcException('Failed to JSON-encode request body: ' . json_last_error_msg());
        }

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        foreach (array_merge($this->defaultHeaders, $headers) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new RpcException('HTTP transport error: ' . $e->getMessage(), null, null, null, $e);
        }

        $status = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $preview = substr($responseBody, 0, 500);
            throw new RpcException(
                "RPC response was not valid JSON (HTTP {$status}): {$preview}",
                $status
            );
        }

        return [$decoded, $status];
    }
}
