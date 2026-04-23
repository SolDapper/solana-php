<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh u64: unsigned 64-bit integer, little-endian.
 *
 * Accepts int (for values within PHP_INT_MAX) or numeric string (for values
 * up to 2^64 - 1). Returns int when the decoded value fits in PHP_INT_MAX,
 * otherwise a numeric string.
 *
 * This type covers the majority of on-chain Solana amounts: lamports, token
 * balances, timestamps, slot numbers, etc.
 */
final class U64Type extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        try {
            $buffer->writeU64($value);
        } catch (\Throwable $e) {
            throw $this->fail('u64: ' . $e->getMessage(), $e);
        }
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return $buffer->readU64();
    }
}
