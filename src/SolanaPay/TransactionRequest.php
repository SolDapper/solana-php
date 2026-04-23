<?php

declare(strict_types=1);

namespace SolanaPhpSdk\SolanaPay;

use SolanaPhpSdk\Exception\SolanaPayException;

/**
 * Typed representation of a Solana Pay transaction-request URL.
 *
 * A transaction request is an INTERACTIVE link: the wallet makes an HTTPS
 * request to the given endpoint, the endpoint returns a pre-built
 * transaction, and the wallet prompts the user to sign. This lets
 * merchants build arbitrarily complex transactions server-side (Anchor
 * program calls, multi-instruction flows, dynamic fees) that the customer
 * simply signs and sends.
 *
 * Spec reference: https://docs.solanapay.com/spec#transaction-request
 *
 *   solana:<link>
 *
 * The <link> is an absolute HTTPS URL pointing to the merchant's endpoint.
 * It's conditionally URL-encoded:
 *
 *   - If $link has no query string, it's embedded raw:
 *       solana:https://example.com/solana-pay
 *
 *   - If $link has a query string, the ENTIRE URL is URL-encoded:
 *       solana:https%3A%2F%2Fexample.com%2Fsolana-pay%3Forder%3D12345
 *
 * The encoding difference is what the spec calls "conditional encoding" —
 * the goal is to produce a shorter URL (and less dense QR code) when the
 * link is simple, and a safely-encoded one when it contains query
 * parameters that might clash with Solana Pay's own params.
 *
 * This class only models the URL shape. The server-side protocol (GET for
 * the label/icon metadata, POST with {account} to return the transaction)
 * is out of scope here — it lives in the merchant's web app.
 */
final class TransactionRequest
{
    public string $link;

    public function __construct(string $link)
    {
        self::validateLink($link);
        $this->link = $link;
    }

    /**
     * Enforce the spec rule that link must be an absolute HTTPS URL.
     */
    public static function validateLink(string $link): void
    {
        if ($link === '') {
            throw new SolanaPayException('Transaction request link cannot be empty');
        }
        $parsed = parse_url($link);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new SolanaPayException("Transaction request link is not a valid absolute URL: '{$link}'");
        }
        if (strtolower($parsed['scheme']) !== 'https') {
            throw new SolanaPayException(
                "Transaction request link must use HTTPS scheme (got '{$parsed['scheme']}')"
            );
        }
    }

    /**
     * True if the link contains a query string. Determines the encoding
     * strategy used by {@see Url::encodeTransaction()}.
     */
    public function hasQueryString(): bool
    {
        return strpos($this->link, '?') !== false;
    }
}
