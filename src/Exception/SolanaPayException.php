<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Exception;

/**
 * Thrown when a Solana Pay URL is malformed or fails spec validation.
 *
 * Separate from InvalidArgumentException to let payment-integration code
 * catch spec-level errors (e.g. "amount exceeds mint decimals") distinctly
 * from general argument errors.
 */
class SolanaPayException extends SolanaException
{
}
