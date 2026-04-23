<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Rpc\Http;

use SolanaPhpSdk\Exception\RpcException;

/**
 * Zero-dependency HTTP client using PHP's bundled cURL extension.
 *
 * Suitable for most production use. No external packages required, sensible
 * defaults (30-second timeout, TLS verification enabled, basic retry of
 * transient network errors disabled by design — leave retries to higher
 * layers that can reason about the RPC semantics).
 *
 * Users who need middleware, connection pooling, async behavior, or
 * integration with an existing HTTP stack should use {@see Psr18HttpClient}
 * instead.
 */
final class CurlHttpClient implements HttpClient
{
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;
    private bool $verifyTls;

    /** @var array<string, string> */
    private array $defaultHeaders;

    /**
     * @param array<string, string> $defaultHeaders Applied to every request (e.g. auth tokens).
     */
    public function __construct(
        int $timeoutSeconds = 30,
        int $connectTimeoutSeconds = 10,
        bool $verifyTls = true,
        array $defaultHeaders = []
    ) {
        if (!extension_loaded('curl')) {
            throw new RpcException('CurlHttpClient requires the curl PHP extension');
        }
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->verifyTls = $verifyTls;
        $this->defaultHeaders = $defaultHeaders;
    }

    public function postJson(string $url, array $jsonBody, array $headers = []): array
    {
        $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RpcException('Failed to JSON-encode request body: ' . json_last_error_msg());
        }

        $merged = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $this->defaultHeaders,
            $headers
        );
        $headerLines = [];
        foreach ($merged as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $responseBody === false) {
            throw new RpcException("cURL transport error ({$errno}): {$error}");
        }

        $decoded = json_decode((string) $responseBody, true);
        if (!is_array($decoded)) {
            // If the body is plain text (e.g. a provider error page), surface
            // a truncated excerpt for diagnostic purposes.
            $preview = substr((string) $responseBody, 0, 500);
            throw new RpcException(
                "RPC response was not valid JSON (HTTP {$status}): {$preview}",
                $status
            );
        }

        return [$decoded, $status];
    }
}
