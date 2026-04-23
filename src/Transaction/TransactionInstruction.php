<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

use SolanaPhpSdk\Keypair\PublicKey;

/**
 * A single instruction: "invoke program X with these accounts and this data".
 *
 * An instruction by itself doesn't do anything — it's a description of work
 * to perform. The actual execution happens when a Message containing one or
 * more instructions is signed and submitted as a Transaction.
 *
 * The three fields map directly onto the on-chain wire format:
 *   - programId: which on-chain program handles this instruction
 *   - accounts:  the AccountMeta list that the program will receive in order
 *   - data:      opaque bytes interpreted by the program (typically
 *                Borsh-encoded instruction args, or a native program's
 *                packed binary layout, or Anchor's 8-byte discriminator
 *                prefix followed by Borsh args)
 */
final class TransactionInstruction
{
    public PublicKey $programId;

    /** @var array<int, AccountMeta> */
    public array $accounts;

    public string $data;

    /**
     * @param array<int, AccountMeta> $accounts
     * @param string $data Raw binary instruction data.
     */
    public function __construct(PublicKey $programId, array $accounts, string $data = '')
    {
        $this->programId = $programId;
        $this->accounts = array_values($accounts);
        $this->data = $data;
    }
}
