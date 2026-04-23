<?php

declare(strict_types=1);

namespace SolanaPhpSdk\SolanaPay;

use SolanaPhpSdk\Keypair\PublicKey;

/**
 * Fluent builder for {@see TransferRequest}.
 *
 * Using the builder reads much more clearly than a long positional
 * constructor call, especially for the common case of building a
 * payment URL inside an order-handler in an ecommerce framework:
 *
 *   $request = TransferRequest::builder($merchantPubkey)
 *       ->amount('10.00')
 *       ->splToken($usdcMint)
 *       ->addReference($orderRef)
 *       ->label('My Store')
 *       ->memo("order:{$orderId}")
 *       ->build();
 *
 *   $url = Url::encodeTransfer($request);
 */
final class TransferRequestBuilder
{
    private PublicKey $recipient;
    private ?string $amount = null;
    private ?PublicKey $splToken = null;

    /** @var array<int, PublicKey> */
    private array $references = [];

    private ?string $label = null;
    private ?string $message = null;
    private ?string $memo = null;

    public function __construct(PublicKey $recipient)
    {
        $this->recipient = $recipient;
    }

    public function amount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function splToken(PublicKey $mint): self
    {
        $this->splToken = $mint;
        return $this;
    }

    public function addReference(PublicKey $reference): self
    {
        $this->references[] = $reference;
        return $this;
    }

    /**
     * @param array<int, PublicKey> $references
     */
    public function references(array $references): self
    {
        $this->references = array_values($references);
        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function message(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function memo(string $memo): self
    {
        $this->memo = $memo;
        return $this;
    }

    public function build(): TransferRequest
    {
        return new TransferRequest(
            $this->recipient,
            $this->amount,
            $this->splToken,
            $this->references,
            $this->label,
            $this->message,
            $this->memo
        );
    }
}
