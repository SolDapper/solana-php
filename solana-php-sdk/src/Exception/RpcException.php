<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Exception;

/**
 * Thrown when an RPC call fails to complete or returns an error.
 *
 * Covers three distinct failure modes, all surfaced through this one class
 * for simple catch ergonomics. Use the accessor methods to distinguish.
 *
 *   1. Transport failure (couldn't reach the server, TLS error, timeout).
 *      {@see getHttpStatus} is null, {@see getRpcErrorCode} is null.
 *
 *   2. HTTP-level failure (e.g. 429, 500 from the RPC provider).
 *      {@see getHttpStatus} is set, {@see getRpcErrorCode} is null.
 *
 *   3. JSON-RPC error (the request was valid but the method failed —
 *      e.g. "Transaction simulation failed", "Block not available").
 *      Both {@see getHttpStatus} (usually 200) and {@see getRpcErrorCode}
 *      are set.
 *
 * The raw error payload from the RPC response is preserved in
 * {@see getRpcErrorData} for callers that need structured diagnostics
 * (e.g. extracting InstructionError details from a failed simulation).
 */
class RpcException extends SolanaException
{
    private ?int $httpStatus;
    private ?int $rpcErrorCode;

    /** @var mixed */
    private $rpcErrorData;

    /**
     * @param mixed $rpcErrorData
     */
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?int $rpcErrorCode = null,
        $rpcErrorData = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->rpcErrorCode = $rpcErrorCode;
        $this->rpcErrorData = $rpcErrorData;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getRpcErrorCode(): ?int
    {
        return $this->rpcErrorCode;
    }

    /**
     * @return mixed
     */
    public function getRpcErrorData()
    {
        return $this->rpcErrorData;
    }
}
