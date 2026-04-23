<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Borsh\Types;

use SolanaPhpSdk\Borsh\BorshType;
use SolanaPhpSdk\Exception\BorshException;
use SolanaPhpSdk\Util\ByteBuffer;

/**
 * Borsh struct: ordered named fields, concatenated with no delimiters.
 *
 * The wire format emits fields strictly in declaration order — this ordering
 * is semantically significant. Field names are metadata only; they do not
 * appear on the wire.
 *
 * PHP native representation is an associative array keyed by field name.
 * Deserialization produces the same shape.
 */
final class StructType extends AbstractPrimitive
{
    /** @var array<string, BorshType> */
    private array $fields;

    /**
     * @param array<string, BorshType> $fields Ordered map of field name to type.
     */
    public function __construct(array $fields)
    {
        if ($fields === []) {
            // An empty struct is technically valid (serializes to zero bytes).
        }
        foreach ($fields as $name => $type) {
            if (!is_string($name) || $name === '') {
                throw new BorshException('Struct field names must be non-empty strings');
            }
            if (!$type instanceof BorshType) {
                throw new BorshException("Struct field '{$name}' must be a BorshType instance");
            }
        }
        $this->fields = $fields;
    }

    public function serialize($value, ByteBuffer $buffer): void
    {
        if (!is_array($value)) {
            throw $this->fail('Struct requires an associative array, got: ' . gettype($value));
        }
        foreach ($this->fields as $name => $type) {
            if (!array_key_exists($name, $value)) {
                throw $this->fail("Missing required struct field: '{$name}'");
            }
            try {
                $type->serialize($value[$name], $buffer);
            } catch (BorshException $e) {
                throw $this->fail("Failed to serialize field '{$name}': " . $e->getMessage(), $e);
            }
        }
    }

    public function deserialize(ByteBuffer $buffer)
    {
        $out = [];
        foreach ($this->fields as $name => $type) {
            try {
                $out[$name] = $type->deserialize($buffer);
            } catch (BorshException $e) {
                throw $this->fail("Failed to deserialize field '{$name}': " . $e->getMessage(), $e);
            }
        }
        return $out;
    }

    /**
     * @return array<string, BorshType>
     */
    public function getFields(): array
    {
        return $this->fields;
    }
}
