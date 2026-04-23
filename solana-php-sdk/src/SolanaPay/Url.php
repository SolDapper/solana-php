<?php

declare(strict_types=1);

namespace SolanaPhpSdk\SolanaPay;

use SolanaPhpSdk\Exception\SolanaPayException;
use SolanaPhpSdk\Keypair\PublicKey;

/**
 * Encode and decode Solana Pay URLs.
 *
 * Two URL shapes exist per the Solana Pay spec:
 *
 *   - Transfer request  ("solana:<pubkey>?...") — non-interactive.
 *   - Transaction request ("solana:<httpsUrl>") — interactive.
 *
 * Encoding rules that are easy to get wrong:
 *
 *   1. Query-string encoding uses application/x-www-form-urlencoded style
 *      (spaces become '+', not '%20'). PHP's urlencode() matches; PHP's
 *      rawurlencode() does NOT.
 *
 *   2. The "solana:" scheme has no authority, so the URL has no "//".
 *      This is a URI path format, not a URL-with-host.
 *
 *   3. Transaction request encoding is *conditional*:
 *        - No query params → embed the HTTPS URL raw.
 *        - Has query params → URL-encode the entire HTTPS URL.
 *      The rationale (per the spec) is to produce shorter / less-dense
 *      QR codes when the merchant's endpoint is simple.
 *
 *   4. `reference` can appear multiple times. The URL preserves order,
 *      and wallets pass the references through to the on-chain
 *      transfer instruction in that order.
 *
 * All methods are static. Use {@see self::encodeTransfer()} and
 * {@see self::parse()} as the primary entry points.
 */
final class Url
{
    public const SCHEME = 'solana';

    private function __construct()
    {
    }

    // ----- Encode --------------------------------------------------------

    /**
     * Encode a TransferRequest as a Solana Pay URL string.
     */
    public static function encodeTransfer(TransferRequest $req): string
    {
        $url = self::SCHEME . ':' . $req->recipient->toBase58();

        $pairs = [];
        if ($req->amount !== null) {
            $pairs[] = 'amount=' . urlencode($req->amount);
        }
        if ($req->splToken !== null) {
            $pairs[] = 'spl-token=' . urlencode($req->splToken->toBase58());
        }
        foreach ($req->references as $ref) {
            $pairs[] = 'reference=' . urlencode($ref->toBase58());
        }
        if ($req->label !== null) {
            $pairs[] = 'label=' . urlencode($req->label);
        }
        if ($req->message !== null) {
            $pairs[] = 'message=' . urlencode($req->message);
        }
        if ($req->memo !== null) {
            $pairs[] = 'memo=' . urlencode($req->memo);
        }

        if ($pairs !== []) {
            $url .= '?' . implode('&', $pairs);
        }
        return $url;
    }

    /**
     * Encode a TransactionRequest as a Solana Pay URL string.
     *
     * Conditional encoding: URLs with query strings get the whole target
     * URL URL-encoded; URLs without are embedded raw.
     */
    public static function encodeTransaction(TransactionRequest $req): string
    {
        if ($req->hasQueryString()) {
            return self::SCHEME . ':' . urlencode($req->link);
        }
        return self::SCHEME . ':' . $req->link;
    }

    // ----- Parse ---------------------------------------------------------

    /**
     * Parse a Solana Pay URL, automatically detecting whether it's a
     * transfer request or a transaction request.
     *
     * Returns a TransferRequest or TransactionRequest instance.
     *
     * @return TransferRequest|TransactionRequest
     */
    public static function parse(string $url)
    {
        if (!self::looksLikeSolanaPayUrl($url)) {
            throw new SolanaPayException(
                "Not a Solana Pay URL (must start with '" . self::SCHEME . ":'): '{$url}'"
            );
        }

        // Strip the "solana:" prefix; everything after is the payload.
        $payload = substr($url, strlen(self::SCHEME) + 1);
        if ($payload === '') {
            throw new SolanaPayException('Solana Pay URL has no payload');
        }

        // Detect transaction requests: payload starts with http/https (raw or encoded).
        $lower = strtolower($payload);
        if (strncmp($lower, 'https://', 8) === 0
            || strncmp($lower, 'http://', 7) === 0
            || strncmp($lower, 'https%3a', 8) === 0
            || strncmp($lower, 'http%3a', 7) === 0
        ) {
            // URL-decode if it was encoded (detect by presence of %-sequences
            // before the first '?').
            $link = self::containsPercentEncoding($payload) ? urldecode($payload) : $payload;
            return new TransactionRequest($link);
        }

        return self::parseTransfer($payload);
    }

    /**
     * Parse the transfer-request payload (the part after "solana:").
     */
    private static function parseTransfer(string $payload): TransferRequest
    {
        // Split into <recipient> and <query>.
        $qPos = strpos($payload, '?');
        if ($qPos === false) {
            $recipientStr = $payload;
            $query = '';
        } else {
            $recipientStr = substr($payload, 0, $qPos);
            $query = substr($payload, $qPos + 1);
        }

        if ($recipientStr === '') {
            throw new SolanaPayException('Transfer request missing recipient');
        }
        try {
            $recipient = new PublicKey($recipientStr);
        } catch (\Throwable $e) {
            throw new SolanaPayException(
                "Invalid recipient public key in transfer request: '{$recipientStr}'",
                0,
                $e
            );
        }

        $amount = null;
        $splToken = null;
        $references = [];
        $label = null;
        $message = null;
        $memo = null;

        if ($query !== '') {
            // We can't use parse_str — it collapses repeated keys to a single
            // value, which would silently drop multi-reference payloads.
            // Walk the pairs manually to preserve ordering and duplicates.
            foreach (explode('&', $query) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    $key = urldecode($pair);
                    $value = '';
                } else {
                    $key = urldecode(substr($pair, 0, $eq));
                    $value = urldecode(substr($pair, $eq + 1));
                }

                switch ($key) {
                    case 'amount':
                        TransferRequest::validateAmount($value);
                        $amount = $value;
                        break;
                    case 'spl-token':
                        try {
                            $splToken = new PublicKey($value);
                        } catch (\Throwable $e) {
                            throw new SolanaPayException("Invalid spl-token: '{$value}'", 0, $e);
                        }
                        break;
                    case 'reference':
                        try {
                            $references[] = new PublicKey($value);
                        } catch (\Throwable $e) {
                            throw new SolanaPayException("Invalid reference: '{$value}'", 0, $e);
                        }
                        break;
                    case 'label':
                        $label = $value;
                        break;
                    case 'message':
                        $message = $value;
                        break;
                    case 'memo':
                        $memo = $value;
                        break;
                    // Unknown keys are ignored per spec (forward compatibility).
                }
            }
        }

        return new TransferRequest($recipient, $amount, $splToken, $references, $label, $message, $memo);
    }

    // ----- Helpers -------------------------------------------------------

    private static function looksLikeSolanaPayUrl(string $url): bool
    {
        return strncasecmp($url, self::SCHEME . ':', strlen(self::SCHEME) + 1) === 0;
    }

    /**
     * True if the first segment before any literal '?' contains a %XX
     * sequence. Used to decide whether a transaction-request URL was
     * URL-encoded (has %-escapes of the scheme colon/slashes) or left raw.
     */
    private static function containsPercentEncoding(string $s): bool
    {
        $firstQ = strpos($s, '?');
        $prefix = $firstQ === false ? $s : substr($s, 0, $firstQ);
        return strpos($prefix, '%') !== false;
    }
}
