<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh string: u32 byte-length prefix followed by UTF-8 bytes.
 *
 * The length prefix counts BYTES, not Unicode codepoints — consistent with
 * Rust's String::len(). PHP's strlen() gives byte length and matches this
 * exactly.
 */
final class StringType extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_string($value)) {
            throw $this->fail('string requires a PHP string, got: ' . gettype($value));
        }
        $len = strlen($value);
        if ($len > 0xFFFFFFFF) {
            throw $this->fail('string exceeds u32 length limit');
        }
        $buffer->writeU32($len);
        $buffer->writeBytes($value);
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $len = $buffer->readU32();
        return $buffer->readBytes($len);
    }
}
