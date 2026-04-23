<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Borsh\BorshType;
use SolanaPhpSdk\Exception\BorshException;

/**
 * Common base for Borsh primitive type implementations.
 *
 * Provides a shared helper for producing informative BorshException instances.
 * Implementations live in separate files (PSR-4) and are assembled by
 * the static Borsh facade.
 */
abstract class AbstractPrimitive implements BorshType
{
    protected function fail(string $message, ?\Throwable $prev = null): BorshException
    {
        return new BorshException($message, 0, $prev);
    }
}
