<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh encoding for a Solana public key: 32 raw bytes, no length prefix.
 *
 * Technically this is equivalent to `FixedArrayType(new U8Type(), 32)`, but
 * appears in so many Solana instruction and account layouts that a dedicated
 * type avoids constant boilerplate and produces a more ergonomic decoded
 * value (a PublicKey instance rather than an array of 32 ints).
 *
 * Accepts any of the PublicKey constructor inputs on serialize: a Base58
 * string, a 32-byte binary string, or a PublicKey instance. Decoded values
 * are always returned as PublicKey instances.
 */
final class PublicKeyType extends AbstractPrimitive
{
    public function serialize($value, ByteBuffer $buffer): void
    {
        try {
            $pk = $value instanceof PublicKey ? $value : new PublicKey($value);
        } catch (\Throwable $e) {
            throw $this->fail('publicKey: ' . $e->getMessage(), $e);
        }
        $buffer->writeBytes($pk->toBytes());
    }

    public function deserialize(ByteBuffer $buffer)
    {
        return PublicKey::fromBytes($buffer->readBytes(PublicKey::LENGTH));
    }
}
