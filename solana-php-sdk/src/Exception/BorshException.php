<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Exception;

/**
 * Thrown when Borsh serialization or deserialization fails.
 *
 * Distinct from generic InvalidArgumentException so callers can catch
 * wire-format errors separately from input validation errors.
 */
class BorshException extends SolanaException
{
}
