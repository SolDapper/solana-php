<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;

/**
 * Represents an on-chain Address Lookup Table (ALT) account for use when
 * compiling v0 transactions.
 *
 * An ALT is a Solana account managed by the AddressLookupTab1e111...1 program
 * that stores up to 256 pubkeys indexed 0..255. Versioned (v0) transactions
 * can reference these addresses by 1-byte index instead of embedding the
 * full 32-byte key, dramatically reducing transaction size when many
 * accounts are touched.
 *
 * This class is just a value object — it doesn't talk to the chain. To
 * fetch a real ALT, call {@see \SolanaPhpSdk\Rpc\RpcClient::getAccountInfo()}
 * and parse the addresses out of the account's data (or more practically,
 * feed the known addresses you extended the table with into this
 * constructor directly).
 *
 * Usage:
 *
 *   $alt = new AddressLookupTableAccount(
 *       new PublicKey('YourLookupTableAddressHere...'),
 *       [
 *           new PublicKey('...'), // index 0
 *           new PublicKey('...'), // index 1
 *           // ... up to 256
 *       ]
 *   );
 *
 *   $msg = MessageV0::compile($payer, $instructions, $blockhash, [$alt]);
 */
final class AddressLookupTableAccount
{
    /** Max addresses a single ALT can hold (u8 index limit). */
    public const MAX_ADDRESSES = 256;

    public PublicKey $key;

    /** @var array<int, PublicKey> */
    public array $addresses;

    /**
     * @param array<int, PublicKey> $addresses
     */
    public function __construct(PublicKey $key, array $addresses)
    {
        if (count($addresses) > self::MAX_ADDRESSES) {
            throw new InvalidArgumentException(
                'ALT cannot hold more than ' . self::MAX_ADDRESSES . ' addresses, got ' . count($addresses)
            );
        }
        foreach ($addresses as $i => $addr) {
            if (!$addr instanceof PublicKey) {
                throw new InvalidArgumentException("addresses[{$i}] must be a PublicKey");
            }
        }
        $this->key = $key;
        $this->addresses = array_values($addresses);
    }

    /**
     * Find the index of a given address in this ALT, or null if absent.
     */
    public function indexOf(PublicKey $address): ?int
    {
        foreach ($this->addresses as $i => $addr) {
            if ($addr->equals($address)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Get the address at $index, or throw if out of bounds.
     */
    public function addressAt(int $index): PublicKey
    {
        if (!isset($this->addresses[$index])) {
            throw new InvalidArgumentException(
                "ALT index {$index} out of bounds (size " . count($this->addresses) . ")"
            );
        }
        return $this->addresses[$index];
    }
}
