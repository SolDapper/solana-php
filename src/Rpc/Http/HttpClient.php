<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Http;

/**
 * Minimal HTTP transport used by the RPC client.
 *
 * Deliberately narrow: the RPC client only ever POSTs JSON bodies and
 * receives JSON responses, so this is all the abstraction needs.
 *
 * Two built-in implementations ship with the SDK:
 *
 *   - {@see CurlHttpClient}: zero-dependency, uses PHP's bundled cURL.
 *     Appropriate for users who just want things to work.
 *
 *   - {@see Psr18HttpClient}: adapts any PSR-18 HTTP client and PSR-17
 *     request factory (Guzzle, Symfony HttpClient, etc.) so callers can
 *     share their application's existing HTTP stack, middleware, and
 *     connection pooling.
 *
 * Users can also implement this interface directly for exotic needs
 * (e.g. mocking in tests, custom auth schemes, proxy rotation).
 */
interface HttpClient
{
    /**
     * POST a JSON body to the given URL and return the parsed response.
     *
     * Implementations must:
     *   - Set Content-Type: application/json
     *   - Set Accept: application/json
     *   - Merge any additional headers from $headers
     *   - Parse the response body as JSON and return the decoded array
     *   - Return the HTTP status code as the second tuple element
     *
     * Implementations should NOT throw on non-2xx HTTP statuses; surface
     * them via the returned status code so the RPC client can construct
     * an appropriate RpcException.
     *
     * @param string $url Full URL including any query string (API keys, etc.)
     * @param array<string, mixed> $jsonBody Will be JSON-encoded as the request body.
     * @param array<string, string> $headers Additional headers beyond Content-Type/Accept.
     *
     * @return array{0: array<string, mixed>, 1: int} [parsedResponse, httpStatusCode]
     *
     * @throws \SolanaPhpSdk\Exception\RpcException On transport failures (network
     *         unreachable, TLS errors, timeouts, non-JSON response bodies).
     */
    public function postJson(string $url, array $jsonBody, array $headers = []): array;
}
