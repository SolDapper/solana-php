<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;

/**
 * A single Address Lookup Table reference inside a MessageV0.
 *
 * Records WHICH ALT is being referenced (by its account key) and which
 * 1-byte indexes into that ALT to pull for writable-resolved and readonly-
 * resolved accounts respectively.
 *
 * Example:
 *   accountKey:      "7EWrbxU7YpHthanStG9yF6KyHS77LBPH6f52ANJmL9rs"
 *   writableIndexes: [5, 12]        (ALT positions 5 and 12 are loaded as writable)
 *   readonlyIndexes: [0, 3, 17]     (positions 0, 3, 17 are loaded as readonly)
 *
 * The runtime resolves each ALT reference by fetching the ALT account,
 * then loading the addresses at the named positions, and appending them
 * (writable first, then readonly) to the transaction's account list.
 */
final class MessageAddressTableLookup
{
    public PublicKey $accountKey;

    /** @var array<int, int> */
    public array $writableIndexes;

    /** @var array<int, int> */
    public array $readonlyIndexes;

    /**
     * @param array<int, int> $writableIndexes
     * @param array<int, int> $readonlyIndexes
     */
    public function __construct(PublicKey $accountKey, array $writableIndexes, array $readonlyIndexes)
    {
        foreach ($writableIndexes as $i => $idx) {
            if (!is_int($idx) || $idx < 0 || $idx > 255) {
                throw new InvalidArgumentException("writableIndexes[{$i}] must be a u8 (0..255)");
            }
        }
        foreach ($readonlyIndexes as $i => $idx) {
            if (!is_int($idx) || $idx < 0 || $idx > 255) {
                throw new InvalidArgumentException("readonlyIndexes[{$i}] must be a u8 (0..255)");
            }
        }
        $this->accountKey = $accountKey;
        $this->writableIndexes = array_values($writableIndexes);
        $this->readonlyIndexes = array_values($readonlyIndexes);
    }
}
