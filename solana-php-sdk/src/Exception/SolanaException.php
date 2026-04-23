<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Exception;

use Exception;

/**
 * Root exception for all Solana SDK errors.
 *
 * All custom exceptions in this SDK extend from this class, allowing
 * consumers to catch any SDK-originated exception with a single catch block.
 */
class SolanaException extends Exception
{
}
