<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Util\Base58;
use SolanaPhpSdk\Util\ByteBuffer;
use SolanaPhpSdk\Util\CompactU16;

/**
 * Versioned (v0) Solana transaction message with support for Address
 * Lookup Tables.
 *
 * This is the newer, compact message format that can reference up to 256
 * accounts total (vs. ~35 for the legacy format) by resolving additional
 * accounts through on-chain ALTs at execution time.
 *
 * Wire format:
 *
 *     [0x80]                          version prefix (version 0 + high bit)
 *     [u8]  numRequiredSignatures
 *     [u8]  numReadonlySignedAccounts
 *     [u8]  numReadonlyUnsignedAccounts
 *     [compact-u16 staticKeysLen]
 *     [staticKey * 32 bytes] * N
 *     [32 bytes recentBlockhash]
 *     [compact-u16 instructionsLen]
 *     [instruction]*:
 *         [u8] programIdIndex
 *         [compact-u16 accountIdxLen][accountIdx (u8)]*
 *         [compact-u16 dataLen][data]
 *     [compact-u16 lookupsLen]
 *     [lookup]*:
 *         [32 bytes accountKey]
 *         [compact-u16 writableLen][writableIdx (u8)]*
 *         [compact-u16 readonlyLen][readonlyIdx (u8)]*
 *
 * Key behavioral details (taken from the reference web3.js implementation):
 *
 *   - The high bit of the first byte (0x80) distinguishes v0 from legacy.
 *     Legacy messages' first byte is numRequiredSignatures, which can
 *     never have that high bit set for a valid transaction (a v0 message
 *     encodes the low 7 bits as the version number; currently only 0 is
 *     supported).
 *
 *   - During compile, ALT-drainable accounts are keys that are:
 *       (a) not signers,
 *       (b) not program-invoked,
 *       (c) present in one of the provided ALTs.
 *     Writable candidates are drained first, then readonly.
 *
 *   - After draining, remaining keys go through the SAME four-category
 *     sort as legacy messages: writable-signers, readonly-signers,
 *     writable-non-signers, readonly-non-signers. The fee payer is always
 *     the first writable-signer.
 *
 *   - Instruction account indices are resolved against the CONCATENATED
 *     account list: [staticKeys ++ writableLookupResolved ++ readonlyLookupResolved].
 *     So if there are 3 static keys + 1 writable lookup + 2 readonly
 *     lookups, a readonly-lookup-0 account gets index 4 (= 3 + 1 + 0).
 */
final class MessageV0
{
    /** The high bit of the first message byte distinguishes versioned from legacy. */
    public const VERSION_PREFIX_MASK = 0x7f;
    public const VERSION_0_PREFIX = 0x80; // (1 << 7) | 0

    public int $numRequiredSignatures = 0;
    public int $numReadonlySignedAccounts = 0;
    public int $numReadonlyUnsignedAccounts = 0;

    /** @var array<int, PublicKey> Static (non-lookup) account keys. */
    public array $staticAccountKeys = [];

    public string $recentBlockhash = '';

    /** @var array<int, CompiledInstructionV0> */
    public array $compiledInstructions = [];

    /** @var array<int, MessageAddressTableLookup> */
    public array $addressTableLookups = [];

    /**
     * Build a MessageV0 from a flat instruction list plus optional ALTs.
     *
     * @param array<int, TransactionInstruction> $instructions
     * @param array<int, AddressLookupTableAccount> $addressLookupTableAccounts
     * @param string $recentBlockhash Base58-encoded blockhash string OR 32 raw bytes.
     */
    public static function compile(
        PublicKey $payer,
        array $instructions,
        string $recentBlockhash,
        array $addressLookupTableAccounts = []
    ): self {
        // Phase 1: Collect every key touched by the transaction, preserving
        // insertion order. The map value records whether each key is
        // signer / writable / invoked-as-program. Later flags OR together
        // any subsequent mentions of the same key with promote-only merge.
        //
        // Using a PublicKey-keyed map in PHP is awkward (PublicKey isn't
        // hashable) so we use the Base58 string as the key, and remember
        // the first-seen order via a companion order array.
        $keyMeta = [];
        $keyOrder = [];

        $setOrUpdate = function (PublicKey $pk, bool $signer, bool $writable, bool $invoked)
            use (&$keyMeta, &$keyOrder): void {
            $addr = $pk->toBase58();
            if (!isset($keyMeta[$addr])) {
                $keyMeta[$addr] = [
                    'pubkey'   => $pk,
                    'isSigner' => false,
                    'isWritable' => false,
                    'isInvoked' => false,
                ];
                $keyOrder[] = $addr;
            }
            if ($signer) {
                $keyMeta[$addr]['isSigner'] = true;
            }
            if ($writable) {
                $keyMeta[$addr]['isWritable'] = true;
            }
            if ($invoked) {
                $keyMeta[$addr]['isInvoked'] = true;
            }
        };

        // Payer is always first, always signer, always writable.
        $setOrUpdate($payer, true, true, false);

        foreach ($instructions as $ix) {
            // Program ID is marked as invoked; this prevents it from being
            // drained into an ALT (programs must always appear in the
            // static account list).
            $setOrUpdate($ix->programId, false, false, true);
            foreach ($ix->accounts as $meta) {
                $setOrUpdate($meta->pubkey, $meta->isSigner, $meta->isWritable, false);
            }
        }

        // Phase 2: Try to drain accounts into each ALT. Writable drains first
        // (within each ALT), then readonly. Signers and invoked-program accounts
        // are never drainable regardless.
        $addressTableLookups = [];
        $drainedWritable = [];
        $drainedReadonly = [];

        foreach ($addressLookupTableAccounts as $altIdx => $alt) {
            if (!$alt instanceof AddressLookupTableAccount) {
                throw new InvalidArgumentException(
                    "addressLookupTableAccounts[{$altIdx}] must be an AddressLookupTableAccount"
                );
            }

            $writableIdxs = [];
            $readonlyIdxs = [];
            $writableDrainedThisAlt = [];
            $readonlyDrainedThisAlt = [];

            // Two passes: writable first, then readonly, matching web3.js.
            foreach ($keyMeta as $addr => $meta) {
                if ($meta['isSigner'] || $meta['isInvoked'] || !$meta['isWritable']) {
                    continue;
                }
                $idx = $alt->indexOf($meta['pubkey']);
                if ($idx !== null) {
                    if ($idx > 255) {
                        throw new InvalidArgumentException('Max lookup table index exceeded');
                    }
                    $writableIdxs[] = $idx;
                    $writableDrainedThisAlt[] = $meta['pubkey'];
                    unset($keyMeta[$addr]);
                    // Also remove from keyOrder so subsequent static-key
                    // assembly doesn't include this address.
                    $keyOrder = array_values(array_filter($keyOrder, fn($a) => $a !== $addr));
                }
            }
            foreach ($keyMeta as $addr => $meta) {
                if ($meta['isSigner'] || $meta['isInvoked'] || $meta['isWritable']) {
                    continue;
                }
                $idx = $alt->indexOf($meta['pubkey']);
                if ($idx !== null) {
                    if ($idx > 255) {
                        throw new InvalidArgumentException('Max lookup table index exceeded');
                    }
                    $readonlyIdxs[] = $idx;
                    $readonlyDrainedThisAlt[] = $meta['pubkey'];
                    unset($keyMeta[$addr]);
                    $keyOrder = array_values(array_filter($keyOrder, fn($a) => $a !== $addr));
                }
            }

            // Only emit a lookup entry if this ALT contributed something.
            if ($writableIdxs !== [] || $readonlyIdxs !== []) {
                $addressTableLookups[] = new MessageAddressTableLookup(
                    $alt->key,
                    $writableIdxs,
                    $readonlyIdxs
                );
                foreach ($writableDrainedThisAlt as $k) {
                    $drainedWritable[] = $k;
                }
                foreach ($readonlyDrainedThisAlt as $k) {
                    $drainedReadonly[] = $k;
                }
            }
        }

        // Phase 3: Partition remaining (non-drained) keys into the four
        // categories in the canonical order.
        $writableSigners = [];
        $readonlySigners = [];
        $writableNonSigners = [];
        $readonlyNonSigners = [];

        foreach ($keyOrder as $addr) {
            if (!isset($keyMeta[$addr])) {
                continue; // Defensive — shouldn't happen given the filter above.
            }
            $m = $keyMeta[$addr];
            if ($m['isSigner'] && $m['isWritable']) {
                $writableSigners[] = $m['pubkey'];
            } elseif ($m['isSigner'] && !$m['isWritable']) {
                $readonlySigners[] = $m['pubkey'];
            } elseif (!$m['isSigner'] && $m['isWritable']) {
                $writableNonSigners[] = $m['pubkey'];
            } else {
                $readonlyNonSigners[] = $m['pubkey'];
            }
        }

        if ($writableSigners === []) {
            throw new InvalidArgumentException('Expected at least one writable signer (the fee payer)');
        }
        if (!$writableSigners[0]->equals($payer)) {
            // This invariant is guaranteed by payer being set first with
            // both signer+writable flags, but assert anyway to catch
            // future regressions.
            throw new InvalidArgumentException('First writable signer must be the fee payer');
        }

        $staticAccountKeys = array_merge(
            $writableSigners, $readonlySigners, $writableNonSigners, $readonlyNonSigners
        );

        if (count($staticAccountKeys) > 256) {
            throw new InvalidArgumentException('Max static account keys length (256) exceeded');
        }

        // Phase 4: Build the instruction index table.
        // Combined account order is: [static..., writable-drained..., readonly-drained...].
        $combinedKeys = array_merge($staticAccountKeys, $drainedWritable, $drainedReadonly);
        $indexOf = [];
        foreach ($combinedKeys as $i => $k) {
            $indexOf[$k->toBase58()] = $i;
        }

        $compiledInstructions = [];
        foreach ($instructions as $ix) {
            $programIdAddr = $ix->programId->toBase58();
            if (!isset($indexOf[$programIdAddr])) {
                throw new InvalidArgumentException(
                    "Program ID {$programIdAddr} not found in account keys (this should never happen)"
                );
            }
            $accountIdxs = [];
            foreach ($ix->accounts as $meta) {
                $addr = $meta->pubkey->toBase58();
                if (!isset($indexOf[$addr])) {
                    throw new InvalidArgumentException(
                        "Account {$addr} not found in account keys"
                    );
                }
                $accountIdxs[] = $indexOf[$addr];
            }
            $compiledInstructions[] = new CompiledInstructionV0(
                $indexOf[$programIdAddr],
                $accountIdxs,
                $ix->data
            );
        }

        $msg = new self();
        $msg->numRequiredSignatures = count($writableSigners) + count($readonlySigners);
        $msg->numReadonlySignedAccounts = count($readonlySigners);
        $msg->numReadonlyUnsignedAccounts = count($readonlyNonSigners);
        $msg->staticAccountKeys = $staticAccountKeys;
        $msg->recentBlockhash = self::normalizeBlockhash($recentBlockhash);
        $msg->compiledInstructions = $compiledInstructions;
        $msg->addressTableLookups = $addressTableLookups;
        return $msg;
    }

    /**
     * Serialize this message to the wire format.
     */
    public function serialize(): string
    {
        $buf = new ByteBuffer();
        $buf->writeU8(self::VERSION_0_PREFIX);
        $buf->writeU8($this->numRequiredSignatures);
        $buf->writeU8($this->numReadonlySignedAccounts);
        $buf->writeU8($this->numReadonlyUnsignedAccounts);

        // static account keys
        $buf->writeBytes(CompactU16::encode(count($this->staticAccountKeys)));
        foreach ($this->staticAccountKeys as $k) {
            $buf->writeBytes($k->toBytes());
        }

        // blockhash — 32 raw bytes
        $buf->writeBytes($this->blockhashBytes());

        // instructions
        $buf->writeBytes(CompactU16::encode(count($this->compiledInstructions)));
        foreach ($this->compiledInstructions as $ix) {
            $buf->writeU8($ix->programIdIndex);
            $buf->writeBytes(CompactU16::encode(count($ix->accountKeyIndexes)));
            foreach ($ix->accountKeyIndexes as $idx) {
                $buf->writeU8($idx);
            }
            $buf->writeBytes(CompactU16::encode(strlen($ix->data)));
            $buf->writeBytes($ix->data);
        }

        // address table lookups
        $buf->writeBytes(CompactU16::encode(count($this->addressTableLookups)));
        foreach ($this->addressTableLookups as $lookup) {
            $buf->writeBytes($lookup->accountKey->toBytes());
            $buf->writeBytes(CompactU16::encode(count($lookup->writableIndexes)));
            foreach ($lookup->writableIndexes as $idx) {
                $buf->writeU8($idx);
            }
            $buf->writeBytes(CompactU16::encode(count($lookup->readonlyIndexes)));
            foreach ($lookup->readonlyIndexes as $idx) {
                $buf->writeU8($idx);
            }
        }

        return $buf->toBytes();
    }

    /**
     * Deserialize a v0 message from wire bytes. Rejects legacy messages.
     */
    public static function deserialize(string $wire): self
    {
        if ($wire === '') {
            throw new InvalidArgumentException('Cannot deserialize empty message bytes');
        }

        $offset = 0;
        $prefix = ord($wire[$offset++]);
        if (($prefix & 0x80) === 0) {
            throw new InvalidArgumentException(
                'Expected versioned message but received legacy message (high bit of first byte unset)'
            );
        }
        $version = $prefix & self::VERSION_PREFIX_MASK;
        if ($version !== 0) {
            throw new InvalidArgumentException(
                "Expected versioned message with version 0 but found version {$version}"
            );
        }

        if (strlen($wire) < $offset + 3) {
            throw new InvalidArgumentException('Message truncated: header');
        }
        $numSig    = ord($wire[$offset++]);
        $numRoSig  = ord($wire[$offset++]);
        $numRoUnsg = ord($wire[$offset++]);

        // static account keys
        [$count, $consumed] = CompactU16::decodeAt($wire, $offset);
        $offset += $consumed;
        if (strlen($wire) < $offset + $count * 32) {
            throw new InvalidArgumentException('Message truncated: static account keys');
        }
        $staticKeys = [];
        for ($i = 0; $i < $count; $i++) {
            $staticKeys[] = new PublicKey(substr($wire, $offset, 32));
            $offset += 32;
        }

        // blockhash
        if (strlen($wire) < $offset + 32) {
            throw new InvalidArgumentException('Message truncated: blockhash');
        }
        $blockhashBytes = substr($wire, $offset, 32);
        $offset += 32;

        // instructions
        [$ixCount, $consumed] = CompactU16::decodeAt($wire, $offset);
        $offset += $consumed;
        $compiledInstructions = [];
        for ($i = 0; $i < $ixCount; $i++) {
            if (strlen($wire) < $offset + 1) {
                throw new InvalidArgumentException('Message truncated: instruction header');
            }
            $programIdIndex = ord($wire[$offset++]);
            [$accIdxCount, $c] = CompactU16::decodeAt($wire, $offset);
            $offset += $c;
            if (strlen($wire) < $offset + $accIdxCount) {
                throw new InvalidArgumentException('Message truncated: instruction account indexes');
            }
            $accIdxs = [];
            for ($j = 0; $j < $accIdxCount; $j++) {
                $accIdxs[] = ord($wire[$offset++]);
            }
            [$dataLen, $c] = CompactU16::decodeAt($wire, $offset);
            $offset += $c;
            if (strlen($wire) < $offset + $dataLen) {
                throw new InvalidArgumentException('Message truncated: instruction data');
            }
            $data = substr($wire, $offset, $dataLen);
            $offset += $dataLen;
            $compiledInstructions[] = new CompiledInstructionV0($programIdIndex, $accIdxs, $data);
        }

        // address table lookups
        [$lookupCount, $c] = CompactU16::decodeAt($wire, $offset);
        $offset += $c;
        $lookups = [];
        for ($i = 0; $i < $lookupCount; $i++) {
            if (strlen($wire) < $offset + 32) {
                throw new InvalidArgumentException('Message truncated: lookup account key');
            }
            $accountKey = new PublicKey(substr($wire, $offset, 32));
            $offset += 32;
            [$wLen, $c] = CompactU16::decodeAt($wire, $offset);
            $offset += $c;
            if (strlen($wire) < $offset + $wLen) {
                throw new InvalidArgumentException('Message truncated: writable indexes');
            }
            $wIdxs = [];
            for ($j = 0; $j < $wLen; $j++) {
                $wIdxs[] = ord($wire[$offset++]);
            }
            [$rLen, $c] = CompactU16::decodeAt($wire, $offset);
            $offset += $c;
            if (strlen($wire) < $offset + $rLen) {
                throw new InvalidArgumentException('Message truncated: readonly indexes');
            }
            $rIdxs = [];
            for ($j = 0; $j < $rLen; $j++) {
                $rIdxs[] = ord($wire[$offset++]);
            }
            $lookups[] = new MessageAddressTableLookup($accountKey, $wIdxs, $rIdxs);
        }

        $msg = new self();
        $msg->numRequiredSignatures = $numSig;
        $msg->numReadonlySignedAccounts = $numRoSig;
        $msg->numReadonlyUnsignedAccounts = $numRoUnsg;
        $msg->staticAccountKeys = $staticKeys;
        $msg->recentBlockhash = Base58::encode($blockhashBytes);
        $msg->compiledInstructions = $compiledInstructions;
        $msg->addressTableLookups = $lookups;
        return $msg;
    }

    /**
     * Total number of accounts (including lookup-resolved) the transaction
     * will load at execution time. Useful for a client-side size estimate.
     */
    public function numAccountKeysFromLookups(): int
    {
        $n = 0;
        foreach ($this->addressTableLookups as $l) {
            $n += count($l->writableIndexes) + count($l->readonlyIndexes);
        }
        return $n;
    }

    /**
     * Resolve a v0 message's lookup indices into concrete pubkeys using
     * the provided ALTs. Returns [staticKeys, writableResolved, readonlyResolved].
     * All three arrays together give the complete account list referenced
     * by the transaction's instruction indices.
     *
     * @param array<int, AddressLookupTableAccount> $alts
     * @return array{0: array<int, PublicKey>, 1: array<int, PublicKey>, 2: array<int, PublicKey>}
     */
    public function resolveAddressLookups(array $alts): array
    {
        $writable = [];
        $readonly = [];
        foreach ($this->addressTableLookups as $lookup) {
            $found = null;
            foreach ($alts as $alt) {
                if ($alt->key->equals($lookup->accountKey)) {
                    $found = $alt;
                    break;
                }
            }
            if ($found === null) {
                throw new InvalidArgumentException(
                    "No provided ALT matches accountKey {$lookup->accountKey->toBase58()}"
                );
            }
            foreach ($lookup->writableIndexes as $idx) {
                $writable[] = $found->addressAt($idx);
            }
            foreach ($lookup->readonlyIndexes as $idx) {
                $readonly[] = $found->addressAt($idx);
            }
        }
        return [$this->staticAccountKeys, $writable, $readonly];
    }

    /**
     * Accept either a Base58-encoded 32-byte hash or raw 32 bytes.
     * Normalize to Base58 for storage.
     */
    private static function normalizeBlockhash(string $input): string
    {
        if (strlen($input) === 32) {
            // Looks like raw bytes (32 octets). Heuristic: if it decodes as Base58
            // to 32 bytes, prefer that interpretation for consistency with
            // user intent; otherwise treat as raw.
            // Base58 of 32 bytes is typically 43-44 chars; 32-char strings
            // are ALMOST ALWAYS raw bytes, not Base58.
            return Base58::encode($input);
        }
        // Treat as Base58 — validate it decodes cleanly to 32 bytes.
        $decoded = Base58::decode($input);
        if (strlen($decoded) !== 32) {
            throw new InvalidArgumentException(
                'recentBlockhash must decode to 32 bytes; got ' . strlen($decoded)
            );
        }
        return $input;
    }

    /**
     * Get the 32 raw bytes of recentBlockhash.
     */
    public function blockhashBytes(): string
    {
        return Base58::decode($this->recentBlockhash);
    }
}
