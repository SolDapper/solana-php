<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Tests\Unit\Rpc;

/**
 * Fluent builder for {@see MockHttpClient} responses.
 *
 * @internal
 */
final class MockResponseBuilder
{
    private MockHttpClient $owner;
    private string $method;

    public function __construct(MockHttpClient $owner, string $method)
    {
        $this->owner = $owner;
        $this->method = $method;
    }

    /**
     * Queue a successful response with the given `result` payload.
     *
     * @param mixed $result
     */
    public function respond($result, int $status = 200): MockHttpClient
    {
        $this->owner->enqueue($this->method, ['result' => $result, 'status' => $status]);
        return $this->owner;
    }

    /**
     * Queue a JSON-RPC error response.
     *
     * @param mixed $data
     */
    public function respondError(string $message, int $code = -32000, $data = null, int $status = 200): MockHttpClient
    {
        $this->owner->enqueue($this->method, [
            'error'  => ['message' => $message, 'code' => $code, 'data' => $data],
            'status' => $status,
        ]);
        return $this->owner;
    }
}
