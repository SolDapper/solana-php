<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh bool: single byte, 0 = false, 1 = true.
 *
 * The Borsh specification treats booleans as u8 with value 0 or 1. Any other
 * byte value on decode raises an exception to preserve the bijective property
 * of the format.
 */
final class BoolType extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_bool($value)) {
            throw $this->fail('bool requires true or false, got: ' . var_export($value, true));
        }
        $buffer->writeBool($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        try {
            return $buffer->readBool();
        } catch (\SolanaPhpSdk\Exception\InvalidArgumentException $e) {
            throw $this->fail('bool: ' . $e->getMessage(), $e);
        }
    }
}
