<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Contract for a Borsh type encoder/decoder.
 *
 * Implementations serialize native PHP values to bytes via a ByteBuffer and
 * deserialize them back. Each implementation owns the mapping between one
 * PHP representation and one Borsh wire format.
 *
 * Implementations must be pure: serialize(x) -> bytes followed by
 * deserialize(bytes) must yield a value equal to x, with no side effects.
 */
interface BorshType
{
    /**
     * Write the given value to the buffer in Borsh wire format.
     *
     * @param mixed $value The native PHP value to encode.
     * @throws \SolanaPhpSdk\Exception\SolanaException If the value doesn't match
     *         the expected shape for this type.
     */
    public function serialize($value, ByteBuffer $buffer): void;

    /**
     * Read a value of this type from the buffer.
     *
     * @return mixed The decoded native PHP value.
     * @throws \SolanaPhpSdk\Exception\SolanaException On malformed input or
     *         unexpected end of buffer.
     */
    public function deserialize(ByteBuffer $buffer);
}
