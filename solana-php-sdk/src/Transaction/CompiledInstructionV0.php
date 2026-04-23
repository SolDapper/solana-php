<?php

declare(strict_types=1);

namespace SolanaPhpSdk\Transaction;

/**
 * A TransactionInstruction compiled against a MessageV0's account index.
 *
 * Unlike the legacy-compiled form (which lives inside Message.php and is
 * not separately named), v0 instructions are first-class because
 * deserializing a v0 message produces them without having access to the
 * original TransactionInstruction objects. Callers reading a v0 message
 * off the wire work with these directly and resolve the indices against
 * the combined (static + ALT-resolved) account list.
 *
 * Fields:
 *   - programIdIndex:     index into [static..writable-lookup..readonly-lookup]
 *   - accountKeyIndexes:  same indexing scheme for each account
 *   - data:               raw instruction data bytes
 */
final class CompiledInstructionV0
{
    public int $programIdIndex;

    /** @var array<int, int> */
    public array $accountKeyIndexes;

    public string $data;

    /**
     * @param array<int, int> $accountKeyIndexes
     */
    public function __construct(int $programIdIndex, array $accountKeyIndexes, string $data)
    {
        $this->programIdIndex = $programIdIndex;
        $this->accountKeyIndexes = $accountKeyIndexes;
        $this->data = $data;
    }
}
