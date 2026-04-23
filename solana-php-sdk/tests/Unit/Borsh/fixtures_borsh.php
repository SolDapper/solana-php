<?php

declare(strict_types=1);

// Golden vectors generated from borsh-js reference implementation.
// These lock in byte-for-byte parity with @solana/web3.js's serialization
// stack. DO NOT edit by hand — regenerate via /tmp/jsref/borsh_vectors.mjs
// if the underlying implementation changes.
//
// Each entry has:
//   - 'name'       : human-readable label for test output
//   - 'schema_fn'  : closure returning a fresh schema (BorshType)
//   - 'value'      : the native PHP value to encode / expected decode output
//   - 'hex'        : the expected wire bytes as lowercase hex
//   - 'decode_eq'  : optional replacement for 'value' when decode output
//                    differs (e.g., large u64 round-trips as a numeric string).
//                    If not set, 'value' is used for both directions.

use SolanaPhpSdk\Borsh\Borsh;

return [
    [
        'name' => 'u8_0',
        'schema_fn' => fn() => Borsh::u8(),
        'value' => 0,
        'hex' => '00',
    ],
    [
        'name' => 'u8_255',
        'schema_fn' => fn() => Borsh::u8(),
        'value' => 255,
        'hex' => 'ff',
    ],
    [
        'name' => 'u16_0x1234',
        'schema_fn' => fn() => Borsh::u16(),
        'value' => 0x1234,
        'hex' => '3412',
    ],
    [
        'name' => 'u32_0xDEADBEEF',
        'schema_fn' => fn() => Borsh::u32(),
        'value' => 0xDEADBEEF,
        'hex' => 'efbeadde',
    ],
    [
        'name' => 'u64_1e9_lamports',
        'schema_fn' => fn() => Borsh::u64(),
        'value' => 1_000_000_000,
        'hex' => '00ca9a3b00000000',
    ],
    [
        'name' => 'u64_max',
        'schema_fn' => fn() => Borsh::u64(),
        'value' => '18446744073709551615',
        'hex' => 'ffffffffffffffff',
        // u64 max exceeds PHP_INT_MAX, so decode returns a numeric string.
        'decode_eq' => '18446744073709551615',
    ],
    [
        'name' => 'i8_neg_one',
        'schema_fn' => fn() => Borsh::i8(),
        'value' => -1,
        'hex' => 'ff',
    ],
    [
        'name' => 'i32_neg_12345',
        'schema_fn' => fn() => Borsh::i32(),
        'value' => -12345,
        'hex' => 'c7cfffff',
    ],
    [
        'name' => 'i64_neg_one',
        'schema_fn' => fn() => Borsh::i64(),
        'value' => -1,
        'hex' => 'ffffffffffffffff',
    ],
    [
        'name' => 'bool_true',
        'schema_fn' => fn() => Borsh::bool(),
        'value' => true,
        'hex' => '01',
    ],
    [
        'name' => 'bool_false',
        'schema_fn' => fn() => Borsh::bool(),
        'value' => false,
        'hex' => '00',
    ],
    [
        'name' => 'string_empty',
        'schema_fn' => fn() => Borsh::string(),
        'value' => '',
        'hex' => '00000000',
    ],
    [
        'name' => 'string_hello',
        'schema_fn' => fn() => Borsh::string(),
        'value' => 'hello',
        'hex' => '0500000068656c6c6f',
    ],
    [
        'name' => 'string_utf8_japanese',
        'schema_fn' => fn() => Borsh::string(),
        'value' => '日本語',
        'hex' => '09000000e697a5e69cace8aa9e',
    ],
    [
        'name' => 'fixed_array_u8_3',
        'schema_fn' => fn() => Borsh::fixedArray(Borsh::u8(), 3),
        'value' => [1, 2, 3],
        'hex' => '010203',
    ],
    [
        'name' => 'vec_u8_three',
        'schema_fn' => fn() => Borsh::vec(Borsh::u8()),
        'value' => [10, 20, 30],
        'hex' => '030000000a141e',
    ],
    [
        'name' => 'vec_u32_three',
        'schema_fn' => fn() => Borsh::vec(Borsh::u32()),
        'value' => [1, 2, 3],
        'hex' => '03000000010000000200000003000000',
    ],
    [
        'name' => 'option_u32_none',
        'schema_fn' => fn() => Borsh::option(Borsh::u32()),
        'value' => null,
        'hex' => '00',
    ],
    [
        'name' => 'option_u32_some_42',
        'schema_fn' => fn() => Borsh::option(Borsh::u32()),
        'value' => 42,
        'hex' => '012a000000',
    ],
    [
        'name' => 'struct_person',
        'schema_fn' => fn() => Borsh::struct([
            'name'  => Borsh::string(),
            'age'   => Borsh::u8(),
            'score' => Borsh::u32(),
        ]),
        'value' => ['name' => 'Alice', 'age' => 30, 'score' => 12345],
        'hex' => '05000000416c6963651e39300000',
    ],
    [
        'name' => 'enum_quit',
        'schema_fn' => fn() => Borsh::enum([
            'quit'    => Borsh::unit(),
            'write'   => Borsh::struct(['text' => Borsh::string()]),
            'move_to' => Borsh::struct(['x' => Borsh::i32(), 'y' => Borsh::i32()]),
        ]),
        'value' => ['quit' => []],
        'hex' => '00',
    ],
    [
        'name' => 'enum_write_hi',
        'schema_fn' => fn() => Borsh::enum([
            'quit'    => Borsh::unit(),
            'write'   => Borsh::struct(['text' => Borsh::string()]),
            'move_to' => Borsh::struct(['x' => Borsh::i32(), 'y' => Borsh::i32()]),
        ]),
        'value' => ['write' => ['text' => 'hi']],
        'hex' => '01020000006869',
    ],
    [
        'name' => 'enum_move_to',
        'schema_fn' => fn() => Borsh::enum([
            'quit'    => Borsh::unit(),
            'write'   => Borsh::struct(['text' => Borsh::string()]),
            'move_to' => Borsh::struct(['x' => Borsh::i32(), 'y' => Borsh::i32()]),
        ]),
        'value' => ['move_to' => ['x' => 10, 'y' => -5]],
        'hex' => '020a000000fbffffff',
    ],
];
