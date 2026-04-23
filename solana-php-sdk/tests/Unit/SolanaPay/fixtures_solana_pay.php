<?php

declare(strict_types=1);

// Golden URL vectors from @solana/pay reference implementation.
// Regenerate via /tmp/jsref/pay_vectors.mjs if the JS SDK encoding changes.

return [
    'transfer_recipient_only' => [
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
    ],

    'transfer_sol_amount_1_5' => [
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN?amount=1.5',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'amount' => '1.5',
    ],

    'transfer_sol_integer_amount' => [
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN?amount=1',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'amount' => '1',
    ],

    'transfer_sol_sub_unit' => [
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN?amount=0.01',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'amount' => '0.01',
    ],

    'transfer_full_usdc' => [
        // Reference output directly from @solana/pay encodeURL().
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN?amount=10&spl-token=EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v&reference=82ZJ7nbGpixjeDCmEhUcmwXYfvurzAgGdtSMuHnUgyny&label=Jungle+Cats+store&message=Jungle+Cats+store+-+your+order+-+%23001234&memo=JC%234098',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'amount' => '10',
        'splToken' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
        'references' => ['82ZJ7nbGpixjeDCmEhUcmwXYfvurzAgGdtSMuHnUgyny'],
        'label' => 'Jungle Cats store',
        'message' => 'Jungle Cats store - your order - #001234',
        'memo' => 'JC#4098',
    ],

    'transfer_multiple_references' => [
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN?reference=82ZJ7nbGpixjeDCmEhUcmwXYfvurzAgGdtSMuHnUgyny&reference=11111111111111111111111111111112',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'references' => [
            '82ZJ7nbGpixjeDCmEhUcmwXYfvurzAgGdtSMuHnUgyny',
            '11111111111111111111111111111112',
        ],
    ],

    'transfer_label_special_chars' => [
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN?label=Caf%C3%A9+%26+Tea',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'label' => 'Café & Tea',
    ],

    'transfer_message_spaces_and_bang' => [
        'url' => 'solana:mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN?message=Thanks+for+all+the+fish%21',
        'recipient' => 'mvines9iiHiQTysrwkJjGf2gb9Ex9jXJX8ns3qwf2kN',
        'message' => 'Thanks for all the fish!',
    ],

    'transaction_no_query' => [
        'url' => 'solana:https://example.com/solana-pay',
        'link' => 'https://example.com/solana-pay',
    ],

    'transaction_with_query' => [
        'url' => 'solana:https%3A%2F%2Fexample.com%2Fsolana-pay%3Forder%3D12345',
        'link' => 'https://example.com/solana-pay?order=12345',
    ],
];
