<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Borsh\BorshType;
use SolanaPhpSdk\Exception\BorshException;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh fixed-size array [T; N]: N elements with no length prefix.
 *
 * Length is part of the type, not the wire format. Both sides must agree on
 * the count statically. Used for things like 32-byte pubkeys (though we also
 * provide a dedicated PublicKeyType for that) or fixed-size hash outputs.
 */
final class FixedArrayType extends AbstractPrimitive
{
    private BorshType $inner;
    private int $length;

    public function __construct(BorshType $inner, int $length)
    {
        if ($length < 0) {
            throw new BorshException("FixedArrayType length must be non-negative, got {$length}");
        }
        $this->inner = $inner;
        $this->length = $length;
    }

    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_array($value)) {
            throw $this->fail("Fixed array [T; {$this->length}] requires an array, got: " . gettype($value));
        }
        if (count($value) !== $this->length) {
            throw $this->fail(
                "Fixed array requires exactly {$this->length} elements, got " . count($value)
            );
        }
        // Iterate in insertion order (which for list arrays equals index order).
        foreach ($value as $item) {
            $this->inner->serialize($item, $buffer);
        }
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $out = [];
        for ($i = 0; $i < $this->length; $i++) {
            $out[] = $this->inner->deserialize($buffer);
        }
        return $out;
    }
}
