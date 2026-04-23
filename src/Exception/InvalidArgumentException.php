<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Exception;

/**
 * Thrown when input validation fails.
 *
 * Used for invalid arguments, malformed input data, or values that
 * fail domain-specific validation (e.g. wrong byte length for a pubkey).
 */
class InvalidArgumentException extends SolanaException
{
}
