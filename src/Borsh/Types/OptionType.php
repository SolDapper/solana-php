<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Borsh\BorshType;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh Option<T>: single tag byte followed by the inner value if Some.
 *
 * Wire format:
 *   0x00            -> None
 *   0x01 + <inner>  -> Some(value)
 *
 * PHP representation:
 *   null            -> None
 *   anything else   -> Some(value)
 *
 * The inner type handles its own validation; this class only manages the tag.
 */
final class OptionType extends AbstractPrimitive
{
    private BorshType $inner;

    public function __construct(BorshType $inner)
    {
        $this->inner = $inner;
    }

    public function serialize($value, ByteBuffer $buffer): void
    {
        if ($value === null) {
            $buffer->writeU8(0);
            return;
        }
        $buffer->writeU8(1);
        $this->inner->serialize($value, $buffer);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $tag = $buffer->readU8();
        if ($tag === 0) {
            return null;
        }
        if ($tag !== 1) {
            throw $this->fail("Option tag must be 0 or 1, got: {$tag}");
        }
        return $this->inner->deserialize($buffer);
    }
}
