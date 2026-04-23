<?php

declare(strict_types=1);

// Golden v0 transaction/message vectors from @solana/web3.js.
// Regenerate via /tmp/jsref/v0_vectors.mjs if the JS SDK encoding changes.
//
// All keypairs are derived via Keypair::fromSeed(str_repeat(chr($n), 32)):
//   payer = seed 0x01  (AKnL4NNf3DGWZJS6cPknBuEGnVsV4A4m5tgebLHaRSZ9)
//   a     = seed 0x02  (9hSR6S7WPtxmTojgo6GG3k4yDPecgJY292j7xrsUGWBu)
//   b     = seed 0x03  (GyGKxMyg1p9SsHfm15MkNUu1u9TN2JtTspcdmrtGUdse)
//   c     = seed 0x04  (EdmxWPmx2WH6WgFfTdu9xfkYf3k1g5wD1zccTVySEEh1)
//
// ALT keys are likewise derived:
//   alt_case2 = Keypair::fromSeed(0x10 * 32).getPublicKey()  (7EWrbxU7YpHthanStG9yF6KyHS77LBPH6f52ANJmL9rs)
//   alt_case3 = Keypair::fromSeed(0x11 * 32).getPublicKey()  (F25s3DdjXdCxYBhh2z8FBusVEMT4b9bGNFVKJi3wFoF4)
//
// Blockhash: GHtXQBsoZHVnNFa9YevAzFr17DJjgHXk3ycTKD5xD3Zi

return [
    'case1_no_alt_transfer' => [
        // Single System::transfer(payer -> a, 1000 lamports). No ALTs.
        'msg_hex' => '80010001038a88e3dd7409f195fd52db2d3cba5d72ca6709bf1d94121bf3748801b40f6f5c8139770ea87d175f56a35466c34c7ecccb8d8a91b4ee37a25df60f5b8fc9b3940000000000000000000000000000000000000000000000000000000000000000e332daf92fabece8b63ff7a44005e5fdfd9afa158756b61c5a95f904b3b8d08101020200010c02000000e80300000000000000',
        'tx_hex'  => '01cf9060503aaf8fce9c180a3823d4826a83f023a954cd5c32941aa91502018dddf93dc3bf90cf97ecb31b867d8c4b98d2be604b77a4cd8544e947c26197574a0380010001038a88e3dd7409f195fd52db2d3cba5d72ca6709bf1d94121bf3748801b40f6f5c8139770ea87d175f56a35466c34c7ecccb8d8a91b4ee37a25df60f5b8fc9b3940000000000000000000000000000000000000000000000000000000000000000e332daf92fabece8b63ff7a44005e5fdfd9afa158756b61c5a95f904b3b8d08101020200010c02000000e80300000000000000',
        'header' => [1, 0, 1],           // [numRequiredSig, numRoSigned, numRoUnsigned]
        'static_keys' => [
            'AKnL4NNf3DGWZJS6cPknBuEGnVsV4A4m5tgebLHaRSZ9',  // payer
            '9hSR6S7WPtxmTojgo6GG3k4yDPecgJY292j7xrsUGWBu',  // a
            '11111111111111111111111111111111',              // System program
        ],
        'num_lookups' => 0,
    ],

    'case2_alt_two_readonly' => [
        // Custom instruction using payer (sw), a (w), b (r), c (r). ALT holds [b, c].
        // Expectation: b and c drain to readonly lookups; static keys shrink to [payer, a, System].
        'msg_hex' => '80010001038a88e3dd7409f195fd52db2d3cba5d72ca6709bf1d94121bf3748801b40f6f5c8139770ea87d175f56a35466c34c7ecccb8d8a91b4ee37a25df60f5b8fc9b3940000000000000000000000000000000000000000000000000000000000000000e332daf92fabece8b63ff7a44005e5fdfd9afa158756b61c5a95f904b3b8d081010204000103040400010203015c9c6df261c9cb840475776aaefcd944b405328fab28f9b3a95ef40490d3de8400020001',
        'header' => [1, 0, 1],
        'static_keys' => [
            'AKnL4NNf3DGWZJS6cPknBuEGnVsV4A4m5tgebLHaRSZ9',
            '9hSR6S7WPtxmTojgo6GG3k4yDPecgJY292j7xrsUGWBu',
            '11111111111111111111111111111111',
        ],
        'lookup_key' => '7EWrbxU7YpHthanStG9yF6KyHS77LBPH6f52ANJmL9rs',
        'writable_idxs' => [],
        'readonly_idxs' => [0, 1],
    ],

    'case3_alt_mixed' => [
        // Custom instruction using payer (sw), a (w via ALT), c (r via ALT). ALT holds [a, b, c].
        // Expectation: a drains to writable lookups, c to readonly. b unused.
        // Static keys: [payer, System]. Instruction indexes: [0 (payer), 2 (first lookup = a), 3 (second lookup = c)].
        'msg_hex' => '80010001028a88e3dd7409f195fd52db2d3cba5d72ca6709bf1d94121bf3748801b40f6f5c0000000000000000000000000000000000000000000000000000000000000000e332daf92fabece8b63ff7a44005e5fdfd9afa158756b61c5a95f904b3b8d081010103000203014201d04ab232742bb4ab3a1368bd4615e4e6d0224ab71a016baf8520a332c977873701000102',
        'header' => [1, 0, 1],
        'static_keys' => [
            'AKnL4NNf3DGWZJS6cPknBuEGnVsV4A4m5tgebLHaRSZ9',
            '11111111111111111111111111111111',
        ],
        'lookup_key' => 'F25s3DdjXdCxYBhh2z8FBusVEMT4b9bGNFVKJi3wFoF4',
        'writable_idxs' => [0],
        'readonly_idxs' => [2],
        'instruction_account_indexes' => [0, 2, 3],
    ],
];
