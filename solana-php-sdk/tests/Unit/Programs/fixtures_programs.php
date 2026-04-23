<?php

declare(strict_types=1);

// Golden instruction-data vectors from @solana/web3.js and @solana/spl-token.
// These lock in byte-for-byte parity with the JavaScript reference. Each
// entry is [description, expected_hex, input_args].
//
// Regenerate via /tmp/jsref/*_vectors.mjs if the JS SDK encoding changes.

return [
    'computeBudget' => [
        // [label, expected_hex, args]
        ['setComputeUnitLimit(200000)',   '02400d0300',          ['setComputeUnitLimit', 200000]],
        ['setComputeUnitLimit(1400000)',  '02c05c1500',          ['setComputeUnitLimit', 1_400_000]],
        ['setComputeUnitPrice(0)',        '030000000000000000',  ['setComputeUnitPrice', 0]],
        ['setComputeUnitPrice(1)',        '030100000000000000',  ['setComputeUnitPrice', 1]],
        ['setComputeUnitPrice(10082)',    '036227000000000000',  ['setComputeUnitPrice', 10082]],
        ['setComputeUnitPrice(1M)',       '0340420f0000000000',  ['setComputeUnitPrice', 1_000_000]],
        ['requestHeapFrame(262144)',      '0100000400',          ['requestHeapFrame', 262144]],
    ],

    'system' => [
        // All vectors use seed=7 fee payer pubkey GmaDrppBC7P5ARKV8g3djiwP89vz1jLK23V2GBjuAEGB.
        // Account lists verified against web3.js output.
        [
            'transfer(1.5 SOL)',
            '02000000002f685900000000',
            ['transfer', 1_500_000_000],
        ],
        [
            'allocate(500)',
            '08000000f401000000000000',
            ['allocate', 500],
        ],
        [
            'assign(TokenProgram)',
            '0100000006ddf6e1d765a193d9cbe146ceeb79ac1cb485ed5f5b37913a8cf5857eff00a9',
            ['assign', 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA'],
        ],
        [
            'createAccount(2039280 lamports, 165 space, TokenProgram)',
            '00000000f01d1f0000000000a50000000000000006ddf6e1d765a193d9cbe146ceeb79ac1cb485ed5f5b37913a8cf5857eff00a9',
            ['createAccount', [2_039_280, 165, 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA']],
        ],
    ],

    'token' => [
        [
            'transfer(1 USDC)',
            '0340420f0000000000',
            ['transfer', 1_000_000],
        ],
        [
            'transferChecked(1 USDC, 6 decimals)',
            '0c40420f000000000006',
            ['transferChecked', [1_000_000, 6]],
        ],
    ],

    'ata' => [
        // Data is all that matters for the data-level check. Account lists
        // are structurally identical between create and createIdempotent.
        [
            'create',
            '', // empty instruction data
            ['create'],
        ],
        [
            'createIdempotent',
            '01',
            ['createIdempotent'],
        ],
    ],
];
