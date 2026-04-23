<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Exception\InvalidArgumentException;
use SolanaPhpSdk\Exception\SolanaException;
use SolanaPhpSdk\Keypair\PublicKey;
use SolanaPhpSdk\Util\ByteBuffer;
use SolanaPhpSdk\Util\CompactU16;

/**
 * A legacy (v1) Solana transaction message.
 *
 * The Message is what signers actually sign — signatures are computed over
 * the serialized Message bytes, not over the outer Transaction. Every signer
 * referenced by any instruction must appear in {@see $accountKeys} at a
 * position dictated by the account ordering rules below.
 *
 * Account ordering
 * ----------------
 * All unique accounts collected across every instruction (plus the fee payer
 * and every instruction's programId) are packed into a single deduplicated
 * account_keys list in this exact order:
 *
 *   1. Writable signers  (fee payer is always first among these)
 *   2. Readonly signers
 *   3. Writable non-signers
 *   4. Readonly non-signers
 *
 * The three header counts express how to interpret this list:
 *   - numRequiredSignatures: count of entries in categories 1 + 2
 *   - numReadonlySignedAccounts: count in category 2
 *   - numReadonlyUnsignedAccounts: count in category 4
 *
 * Wire format (legacy)
 * --------------------
 *   header            (3 bytes)
 *   account_keys      (compact-u16 count + 32 bytes * count)
 *   recent_blockhash  (32 bytes)
 *   instructions      (compact-u16 count + N instructions, each:
 *                        program_id_index: u8
 *                        accounts: compact-u16 count + u8 indices
 *                        data: compact-u16 len + bytes)
 *
 * Note: this implementation covers the legacy message format only.
 * VersionedMessage (v0) with address lookup tables is a separate class.
 */
final class Message
{
    public int $numRequiredSignatures;
    public int $numReadonlySignedAccounts;
    public int $numReadonlyUnsignedAccounts;

    /** @var array<int, PublicKey> */
    public array $accountKeys;

    /** @var string 32-byte recent blockhash */
    public string $recentBlockhash;

    /**
     * Compiled instructions: each entry has programIdIndex (int),
     * accounts (array<int>) — indices into $accountKeys — and
     * data (string).
     *
     * @var array<int, array{programIdIndex: int, accounts: array<int, int>, data: string}>
     */
    public array $instructions;

    /**
     * @param array<int, PublicKey> $accountKeys
     * @param array<int, array{programIdIndex: int, accounts: array<int, int>, data: string}> $instructions
     */
    public function __construct(
        int $numRequiredSignatures,
        int $numReadonlySignedAccounts,
        int $numReadonlyUnsignedAccounts,
        array $accountKeys,
        string $recentBlockhash,
        array $instructions
    ) {
        if (strlen($recentBlockhash) !== 32) {
            throw new InvalidArgumentException('recentBlockhash must be exactly 32 bytes');
        }
        $this->numRequiredSignatures = $numRequiredSignatures;
        $this->numReadonlySignedAccounts = $numReadonlySignedAccounts;
        $this->numReadonlyUnsignedAccounts = $numReadonlyUnsignedAccounts;
        $this->accountKeys = array_values($accountKeys);
        $this->recentBlockhash = $recentBlockhash;
        $this->instructions = array_values($instructions);
    }

    /**
     * Compile a list of TransactionInstructions into a Message.
     *
     * Performs the account dedup-and-order algorithm described in the class
     * docblock. The fee payer is always the first account and is treated as
     * a writable signer whether or not it appears explicitly in any
     * instruction's account list.
     *
     * @param array<int, TransactionInstruction> $instructions
     * @param PublicKey $feePayer
     * @param string $recentBlockhash 32-byte blockhash (Base58 or raw bytes accepted).
     */
    public static function compile(
        array $instructions,
        PublicKey $feePayer,
        string $recentBlockhash
    ): self {
        if ($instructions === []) {
            throw new InvalidArgumentException('Cannot compile a message with no instructions');
        }

        // Normalize blockhash to raw 32 bytes.
        $bhBytes = strlen($recentBlockhash) === 32
            ? $recentBlockhash
            : (new PublicKey($recentBlockhash))->toBytes();

        // Collect every account reference into a map keyed by raw pubkey bytes,
        // accumulating the strictest (isSigner || ..., isWritable || ...) flags.
        // The fee payer is seeded first so it ends up at index 0 in the
        // writable-signers category.
        $all = [];
        $seedKey = $feePayer->toBytes();
        $all[$seedKey] = [
            'pubkey'     => $feePayer,
            'isSigner'   => true,
            'isWritable' => true,
        ];

        foreach ($instructions as $ix) {
            // Include each instruction's programId as a readonly non-signer reference.
            $pid = $ix->programId->toBytes();
            if (!isset($all[$pid])) {
                $all[$pid] = [
                    'pubkey'     => $ix->programId,
                    'isSigner'   => false,
                    'isWritable' => false,
                ];
            }
            // The programId is never a signer and never writable in its
            // capacity as program. (If it appears separately as a regular
            // account that IS a signer/writable, the merge below handles
            // that, but this is the conservative baseline.)

            foreach ($ix->accounts as $acc) {
                $k = $acc->pubkey->toBytes();
                if (isset($all[$k])) {
                    // Merge flags — OR them together so any instruction needing
                    // signer/writable promotes the account.
                    $all[$k]['isSigner']   = $all[$k]['isSigner']   || $acc->isSigner;
                    $all[$k]['isWritable'] = $all[$k]['isWritable'] || $acc->isWritable;
                } else {
                    $all[$k] = [
                        'pubkey'     => $acc->pubkey,
                        'isSigner'   => $acc->isSigner,
                        'isWritable' => $acc->isWritable,
                    ];
                }
            }
        }

        // Partition into the four categories required by the wire format.
        // Within each category, we preserve insertion order (matches
        // web3.js compileMessage behavior and is stable across reorderings
        // that don't change the category assignments).
        $writableSigners   = [];
        $readonlySigners   = [];
        $writableNonSigner = [];
        $readonlyNonSigner = [];

        foreach ($all as $entry) {
            if ($entry['isSigner'] && $entry['isWritable']) {
                $writableSigners[] = $entry['pubkey'];
            } elseif ($entry['isSigner']) {
                $readonlySigners[] = $entry['pubkey'];
            } elseif ($entry['isWritable']) {
                $writableNonSigner[] = $entry['pubkey'];
            } else {
                $readonlyNonSigner[] = $entry['pubkey'];
            }
        }

        // Fee payer is always at index 0 by virtue of being seeded first in
        // writable-signers. Assert this invariant.
        if ($writableSigners === [] || !$writableSigners[0]->equals($feePayer)) {
            throw new SolanaException('Fee payer must be the first writable signer'); // @codeCoverageIgnore
        }

        $orderedAccounts = array_merge(
            $writableSigners,
            $readonlySigners,
            $writableNonSigner,
            $readonlyNonSigner
        );

        // Index lookup for compiling instruction account references.
        $indexOf = [];
        foreach ($orderedAccounts as $i => $pk) {
            $indexOf[$pk->toBytes()] = $i;
        }

        // Compile each instruction: programIdIndex + account indices + data.
        $compiled = [];
        foreach ($instructions as $ix) {
            $accIndices = [];
            foreach ($ix->accounts as $acc) {
                $k = $acc->pubkey->toBytes();
                if (!isset($indexOf[$k])) {
                    // Impossible if our collection logic above is correct,
                    // but defend against it rather than emit a corrupt message.
                    throw new SolanaException( // @codeCoverageIgnore
                        'Internal error: account not found in compiled key list'
                    );
                }
                $accIndices[] = $indexOf[$k];
            }
            $compiled[] = [
                'programIdIndex' => $indexOf[$ix->programId->toBytes()],
                'accounts'       => $accIndices,
                'data'           => $ix->data,
            ];
        }

        return new self(
            count($writableSigners) + count($readonlySigners),
            count($readonlySigners),
            count($readonlyNonSigner),
            $orderedAccounts,
            $bhBytes,
            $compiled
        );
    }

    /**
     * Serialize this Message to the legacy wire format.
     */
    public function serialize(): string
    {
        $buf = new ByteBuffer();

        // Header
        $buf->writeU8($this->numRequiredSignatures);
        $buf->writeU8($this->numReadonlySignedAccounts);
        $buf->writeU8($this->numReadonlyUnsignedAccounts);

        // account_keys
        $buf->writeBytes(CompactU16::encode(count($this->accountKeys)));
        foreach ($this->accountKeys as $pk) {
            $buf->writeBytes($pk->toBytes());
        }

        // recent_blockhash
        $buf->writeBytes($this->recentBlockhash);

        // instructions
        $buf->writeBytes(CompactU16::encode(count($this->instructions)));
        foreach ($this->instructions as $ix) {
            $buf->writeU8($ix['programIdIndex']);
            $buf->writeBytes(CompactU16::encode(count($ix['accounts'])));
            foreach ($ix['accounts'] as $accIndex) {
                $buf->writeU8($accIndex);
            }
            $buf->writeBytes(CompactU16::encode(strlen($ix['data'])));
            $buf->writeBytes($ix['data']);
        }

        return $buf->toBytes();
    }

    /**
     * Parse a legacy-format Message from raw bytes.
     */
    public static function deserialize(string $bytes): self
    {
        $buf = ByteBuffer::fromBytes($bytes);

        $numRequiredSignatures      = $buf->readU8();
        $numReadonlySignedAccounts  = $buf->readU8();
        $numReadonlyUnsignedAccounts = $buf->readU8();

        $numAccounts = CompactU16::decode($buf);
        $accountKeys = [];
        for ($i = 0; $i < $numAccounts; $i++) {
            $accountKeys[] = PublicKey::fromBytes($buf->readBytes(32));
        }

        $recentBlockhash = $buf->readBytes(32);

        $numInstructions = CompactU16::decode($buf);
        $instructions = [];
        for ($i = 0; $i < $numInstructions; $i++) {
            $programIdIndex = $buf->readU8();
            $numAccountIndices = CompactU16::decode($buf);
            $accIndices = [];
            for ($j = 0; $j < $numAccountIndices; $j++) {
                $accIndices[] = $buf->readU8();
            }
            $dataLen = CompactU16::decode($buf);
            $data = $buf->readBytes($dataLen);
            $instructions[] = [
                'programIdIndex' => $programIdIndex,
                'accounts'       => $accIndices,
                'data'           => $data,
            ];
        }

        return new self(
            $numRequiredSignatures,
            $numReadonlySignedAccounts,
            $numReadonlyUnsignedAccounts,
            $accountKeys,
            $recentBlockhash,
            $instructions
        );
    }

    /**
     * True if the account at the given index is a signer, based on header counts.
     */
    public function isAccountSigner(int $index): bool
    {
        return $index < $this->numRequiredSignatures;
    }

    /**
     * True if the account at the given index is writable, based on header counts.
     */
    public function isAccountWritable(int $index): bool
    {
        $numAccounts = count($this->accountKeys);
        // Writable signers: indices [0, numRequiredSignatures - numReadonlySignedAccounts)
        // Writable non-signers: indices [numRequiredSignatures, numAccounts - numReadonlyUnsignedAccounts)
        if ($index < $this->numRequiredSignatures - $this->numReadonlySignedAccounts) {
            return true;
        }
        if ($index >= $this->numRequiredSignatures
            && $index < $numAccounts - $this->numReadonlyUnsignedAccounts) {
            return true;
        }
        return false;
    }
}
